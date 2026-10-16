<?php

namespace Ordering\Infrastructure\Persistence;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Ordering\Domain\BuyerId;
use Ordering\Domain\Cancellation;
use Ordering\Domain\CancelledBy;
use Ordering\Domain\LineQuantity;
use Ordering\Domain\LineStatus;
use Ordering\Domain\OfferId;
use Ordering\Domain\Order;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderLine;
use Ordering\Domain\OrderNotFound;
use Ordering\Domain\OrderRepository;
use Ordering\Domain\OrderStatus;
use Ordering\Domain\Payment;
use Ordering\Domain\PaymentId;
use Ordering\Domain\ProductName;
use Ordering\Domain\Reason;
use Ordering\Domain\Refund;
use Ordering\Domain\RefundId;
use Ordering\Domain\RefundReason;
use Ordering\Domain\RefundStatus;
use Ordering\Domain\Rejection;
use Ordering\Domain\RejectionReason;
use Ordering\Domain\SellerId;
use Ordering\Domain\Shipment;
use Ordering\Domain\TrackingNumber;
use SellerPortal\Infrastructure\Persistence\NextId;
use SellerPortal\Infrastructure\Persistence\SqlTimestamp;
use SharedKernel\ConcurrencyConflict;
use SharedKernel\Currency;
use SharedKernel\InvalidValue;
use SharedKernel\Money;
use stdClass;
use UnexpectedValueException;

/**
 * Repozytorium zamówień na Query Builderze (ADR-0002), wersjonowane jak
 * `SqlOfferRepository`: INSERT przy wersji 0, inaczej `UPDATE ... WHERE
 * version = ?`, a 0 wierszy to `ConcurrencyConflict`.
 *
 * DLACZEGO pozycje i zwroty przez upsert, a nie „usuń i wstaw”: agregat
 * trzyma je w całości, a upsert po kluczu naturalnym (oferta, `refund_id`)
 * nie ma okna, w którym czytelnik widzi zamówienie bez pozycji.
 *
 * `save()` nie zmienia obiektu w pamięci ani nie zwalnia zdarzeń (robi to
 * handler po zapisie). Każde zdarzenie ląduje w `order_events` w tej samej
 * transakcji co stan.
 */
final class SqlOrderRepository implements OrderRepository
{
    public function nextIdentity(): OrderId
    {
        return OrderId::fromInt(NextId::for('orders'));
    }

    public function get(OrderId $id): Order
    {
        $row = DB::table('orders')->where('id', $id->value())->first();

        if ($row === null) {
            throw new OrderNotFound;
        }

        $lines = DB::table('order_lines')->where('order_id', $id->value())->orderBy('position')->get()->all();
        $refunds = DB::table('order_refunds')->where('order_id', $id->value())->orderBy('id')->get()->all();

        return $this->hydrate($row, $lines, $refunds);
    }

    public function save(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $newVersion = $order->version() + 1;
            $now = SqlTimestamp::format(now()->toDateTimeImmutable());

            if ($order->version() === 0) {
                DB::table('orders')->insert($this->row($order) + [
                    'id' => $order->id()->value(),
                    'buyer_user_id' => $order->buyerId()->value(),
                    'seller_id' => $order->sellerId()->value(),
                    'currency' => $order->total()->currency()->value,
                    'placed_at' => SqlTimestamp::format($order->placedAt()),
                    'payment_deadline' => SqlTimestamp::format($order->paymentDeadline()),
                    'version' => $newVersion,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $updated = DB::table('orders')
                    ->where('id', $order->id()->value())
                    ->where('version', $order->version())
                    ->update($this->row($order) + ['version' => $newVersion, 'updated_at' => $now]);

                if ($updated === 0) {
                    throw new ConcurrencyConflict;
                }
            }

            $this->saveLines($order);
            $this->saveRefunds($order);
            OrderEventLog::record($order->id()->value(), $order->pendingEvents());
        });
    }

    public function idsDueForExpiry(DateTimeImmutable $now, int $afterId, int $limit): array
    {
        $threshold = $now->sub(new DateInterval(Order::WEBHOOK_GRACE));

        return DB::table('orders')
            ->whereIn('status', [OrderStatus::Placed->value, OrderStatus::AwaitingPayment->value])
            ->where('payment_deadline', '<=', SqlTimestamp::format($threshold))
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id): OrderId => OrderId::fromInt((int) $id))
            ->all();
    }

    /**
     * Kolumny zmienne w czasie życia zamówienia (tożsamość, strony, waluta
     * i terminy są stałe i dochodzą tylko przy INSERT).
     *
     * @return array<string, mixed>
     */
    private function row(Order $order): array
    {
        $payment = $order->payment();
        $rejection = $order->rejection();
        $cancellation = $order->cancellation();
        $shipment = $order->shipment();

        return [
            'status' => $order->status()->value,
            'total_cents' => $order->total()->cents(),
            'payment_id' => $payment?->paymentId->value(),
            'paid_amount_cents' => $payment?->amount->cents(),
            'paid_at' => $payment === null ? null : SqlTimestamp::format($payment->paidAt),
            'rejection_reason' => $rejection?->reason->value,
            'rejection_offer_id' => $rejection?->offerId->value(),
            'cancelled_by' => $cancellation?->by->value,
            'cancellation_reason' => $cancellation?->reason?->value(),
            'tracking_number' => $shipment?->trackingNumber->value(),
            'shipped_at' => $shipment === null ? null : SqlTimestamp::format($shipment->shippedAt),
        ];
    }

    private function saveLines(Order $order): void
    {
        $rows = [];

        foreach ($order->lines() as $position => $line) {
            $rows[] = [
                'order_id' => $order->id()->value(),
                'position' => $position,
                'offer_id' => $line->offerId()->value(),
                'product_name' => $line->productName()->value(),
                'unit_price_cents' => $line->unitPrice()->cents(),
                'quantity' => $line->quantity()->value(),
                'status' => $line->status()->value,
            ];
        }

        // Cena, nazwa i ilość są zamrożone: zmienia się tylko status (rezerwacja).
        DB::table('order_lines')->upsert($rows, ['order_id', 'offer_id'], ['position', 'status']);
    }

    private function saveRefunds(Order $order): void
    {
        $rows = array_map(fn (Refund $refund): array => [
            'refund_id' => $refund->refundId->value(),
            'order_id' => $order->id()->value(),
            'payment_id' => $refund->paymentId->value(),
            'amount_cents' => $refund->amount->cents(),
            'reason' => $refund->reason->value,
            'status' => $refund->status->value,
            'requested_at' => SqlTimestamp::format($refund->requestedAt),
            'completed_at' => $refund->completedAt === null ? null : SqlTimestamp::format($refund->completedAt),
        ], $order->refunds());

        if ($rows !== []) {
            DB::table('order_refunds')->upsert($rows, ['refund_id'], ['status', 'completed_at']);
        }
    }

    /**
     * @param  list<stdClass>  $lineRows  w kolejności `position`
     * @param  list<stdClass>  $refundRows
     */
    private function hydrate(stdClass $row, array $lineRows, array $refundRows): Order
    {
        $id = (int) $row->id;

        try {
            $currency = Currency::tryFrom(trim((string) $row->currency))
                ?? throw new UnexpectedValueException("nieznana waluta '{$row->currency}'");
            $status = OrderStatus::tryFrom((string) $row->status)
                ?? throw new UnexpectedValueException("nieznany status '{$row->status}'");

            $lines = array_map(fn (stdClass $l): OrderLine => OrderLine::reconstitute(
                OfferId::fromInt((int) $l->offer_id),
                ProductName::fromString($l->product_name),
                Money::fromCents((int) $l->unit_price_cents, $currency),
                LineQuantity::fromInt((int) $l->quantity),
                LineStatus::tryFrom((string) $l->status)
                    ?? throw new UnexpectedValueException("nieznany status pozycji '{$l->status}'"),
            ), $lineRows);

            $refunds = array_map(fn (stdClass $r): Refund => new Refund(
                RefundId::fromString($r->refund_id),
                PaymentId::fromString($r->payment_id),
                Money::fromCents((int) $r->amount_cents, $currency),
                RefundReason::tryFrom((string) $r->reason)
                    ?? throw new UnexpectedValueException("nieznany powód zwrotu '{$r->reason}'"),
                RefundStatus::tryFrom((string) $r->status)
                    ?? throw new UnexpectedValueException("nieznany status zwrotu '{$r->status}'"),
                SqlTimestamp::parse($r->requested_at),
                $r->completed_at === null ? null : SqlTimestamp::parse($r->completed_at),
            ), $refundRows);

            $payment = null;
            if ($row->payment_id !== null) {
                $payment = new Payment(
                    PaymentId::fromString($row->payment_id),
                    Money::fromCents((int) $row->paid_amount_cents, $currency),
                    SqlTimestamp::parse($row->paid_at),
                );
            }

            $rejection = $row->rejection_reason === null ? null : new Rejection(
                RejectionReason::tryFrom((string) $row->rejection_reason)
                    ?? throw new UnexpectedValueException("nieznany powód odrzucenia '{$row->rejection_reason}'"),
                OfferId::fromInt((int) $row->rejection_offer_id),
            );

            $cancellation = $row->cancelled_by === null ? null : new Cancellation(
                CancelledBy::tryFrom((string) $row->cancelled_by)
                    ?? throw new UnexpectedValueException("nieznany anulujący '{$row->cancelled_by}'"),
                $row->cancellation_reason === null ? null : Reason::fromString($row->cancellation_reason),
            );

            $shipment = $row->tracking_number === null ? null : new Shipment(
                TrackingNumber::fromString($row->tracking_number),
                SqlTimestamp::parse($row->shipped_at),
            );

            return Order::reconstitute(
                OrderId::fromInt($id),
                BuyerId::fromInt((int) $row->buyer_user_id),
                SellerId::fromInt((int) $row->seller_id),
                $lines,
                $status,
                SqlTimestamp::parse($row->placed_at),
                SqlTimestamp::parse($row->payment_deadline),
                $payment,
                $refunds,
                $rejection,
                $cancellation,
                $shipment,
                (int) $row->version,
            );
        } catch (UnexpectedValueException|InvalidValue $e) {
            throw new UnexpectedValueException("Uszkodzony wiersz zamówienia #{$id}: {$e->getMessage()}", previous: $e);
        }
    }
}
