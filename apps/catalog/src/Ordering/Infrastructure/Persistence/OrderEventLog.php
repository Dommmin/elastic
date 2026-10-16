<?php

namespace Ordering\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use Ordering\Domain\Event\LatePaymentReceived;
use Ordering\Domain\Event\OrderAwaitingPayment;
use Ordering\Domain\Event\OrderCancelled;
use Ordering\Domain\Event\OrderExpired;
use Ordering\Domain\Event\OrderLineReserved;
use Ordering\Domain\Event\OrderPaid;
use Ordering\Domain\Event\OrderPaymentFailed;
use Ordering\Domain\Event\OrderPlaced;
use Ordering\Domain\Event\OrderRejected;
use Ordering\Domain\Event\OrderShipped;
use Ordering\Domain\Event\RefundCompleted;
use Ordering\Domain\Event\RefundRequested;
use SellerPortal\Infrastructure\Persistence\SqlTimestamp;
use SharedKernel\DomainEvent;

/**
 * Dziennik zdarzeń zamówienia (`order_events`) pod oś czasu kupującego.
 *
 * DLACZEGO osobno od outboxu: zdarzenia Order nie opuszczają kontekstu, więc
 * nie mają kontraktu Published Language. DLACZEGO jawna mapa zamiast
 * refleksji: zapisane nazwy i klucze payloadu czyta później oś czasu, więc
 * zmiana nazwy klasy albo parametru nie może po cichu zmienić danych;
 * nowe zdarzenie bez wpisu tutaj przerywa zapis (jak `OfferIntegrationEvents`).
 */
final class OrderEventLog
{
    /**
     * @param  list<DomainEvent>  $events
     */
    public static function record(int $orderId, array $events): void
    {
        foreach ($events as $event) {
            DB::table('order_events')->insert([
                'order_id' => $orderId,
                'event_type' => self::type($event),
                'payload' => json_encode(self::payload($event), JSON_THROW_ON_ERROR),
                'occurred_at' => SqlTimestamp::format($event->occurredAt()),
            ]);
        }
    }

    /**
     * @throws LogicException dla zdarzenia bez wpisu w mapie
     */
    public static function type(DomainEvent $event): string
    {
        return match (true) {
            $event instanceof OrderPlaced => 'order.placed',
            $event instanceof OrderLineReserved => 'order.line_reserved',
            $event instanceof OrderAwaitingPayment => 'order.awaiting_payment',
            $event instanceof OrderRejected => 'order.rejected',
            $event instanceof OrderPaid => 'order.paid',
            $event instanceof OrderPaymentFailed => 'order.payment_failed',
            $event instanceof LatePaymentReceived => 'order.late_payment_received',
            $event instanceof OrderExpired => 'order.expired',
            $event instanceof OrderCancelled => 'order.cancelled',
            $event instanceof OrderShipped => 'order.shipped',
            $event instanceof RefundRequested => 'order.refund_requested',
            $event instanceof RefundCompleted => 'order.refund_completed',
            default => throw new LogicException('Zdarzenie '.$event::class.' nie ma wpisu w OrderEventLog.'),
        };
    }

    /**
     * @return array<string, scalar|null>
     *
     * @throws LogicException dla zdarzenia bez wpisu w mapie
     */
    public static function payload(DomainEvent $event): array
    {
        return match (true) {
            $event instanceof OrderPlaced => [
                'order_id' => $event->orderId->value(),
                'buyer_id' => $event->buyerId->value(),
                'seller_id' => $event->sellerId->value(),
                'total_cents' => $event->total->cents(),
                'total_currency' => $event->total->currency()->value,
            ],
            $event instanceof OrderLineReserved => [
                'order_id' => $event->orderId->value(),
                'offer_id' => $event->offerId->value(),
            ],
            $event instanceof OrderAwaitingPayment => [
                'order_id' => $event->orderId->value(),
                'deadline' => self::date($event->deadline),
            ],
            $event instanceof OrderRejected => [
                'order_id' => $event->orderId->value(),
                'reason' => $event->reason->value,
                'offer_id' => $event->offerId->value(),
            ],
            $event instanceof OrderPaid => [
                'order_id' => $event->orderId->value(),
                'payment_id' => $event->paymentId->value(),
                'amount_cents' => $event->amount->cents(),
                'amount_currency' => $event->amount->currency()->value,
            ],
            $event instanceof OrderPaymentFailed => [
                'order_id' => $event->orderId->value(),
                'payment_id' => $event->paymentId->value(),
            ],
            $event instanceof LatePaymentReceived => [
                'order_id' => $event->orderId->value(),
                'payment_id' => $event->paymentId->value(),
                'amount_cents' => $event->amount->cents(),
                'amount_currency' => $event->amount->currency()->value,
            ],
            $event instanceof OrderExpired => [
                'order_id' => $event->orderId->value(),
            ],
            $event instanceof OrderCancelled => [
                'order_id' => $event->orderId->value(),
                'by' => $event->by->value,
                'reason' => $event->reason?->value(),
            ],
            $event instanceof OrderShipped => [
                'order_id' => $event->orderId->value(),
                'tracking_number' => $event->trackingNumber->value(),
            ],
            $event instanceof RefundRequested => [
                'order_id' => $event->orderId->value(),
                'refund_id' => $event->refundId->value(),
                'payment_id' => $event->paymentId->value(),
                'amount_cents' => $event->amount->cents(),
                'amount_currency' => $event->amount->currency()->value,
                'reason' => $event->reason->value,
            ],
            $event instanceof RefundCompleted => [
                'order_id' => $event->orderId->value(),
                'refund_id' => $event->refundId->value(),
            ],
            default => throw new LogicException('Zdarzenie '.$event::class.' nie ma wpisu w OrderEventLog.'),
        };
    }

    private static function date(DateTimeImmutable $moment): string
    {
        return $moment->format(DateTimeInterface::ATOM);
    }
}
