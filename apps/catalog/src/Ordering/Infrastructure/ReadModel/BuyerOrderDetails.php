<?php

namespace Ordering\Infrastructure\ReadModel;

use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ordering\Domain\OrderStatus;

/**
 * Model odczytu szczegółów zamówienia kupującego, z gotowymi polskimi opisami
 * powodów (odrzucenia, anulowania) i czasem do terminu liczonym NA SERWERZE.
 *
 * DLACZEGO `seconds_to_deadline` z serwera: zegar przeglądarki może się
 * spóźniać lub śpieszyć; odliczanie w UI startuje od tej wartości, a nie od
 * `payment_deadline` porównywanego z lokalnym czasem (Review Focus 4).
 * DLACZEGO nazwa produktu w powodzie odrzucenia z pozycji zamówienia: to
 * migawka z chwili zakupu, więc opis nie zmienia się razem z ofertą.
 * Właściciela zamówienia sprawdza wcześniej `OrderAccess`; ten model zakłada, że `$orderId` jest własny.
 */
final class BuyerOrderDetails
{
    /**
     * @return array{order: array<string, mixed>, timeline: list<array{id: int, type: string, occurred_at: string}>}
     */
    public function for(int $orderId, DateTimeImmutable $now): array
    {
        $row = DB::table('orders')
            ->join('sellers', 'sellers.id', '=', 'orders.seller_id')
            ->where('orders.id', $orderId)
            ->select('orders.*', 'sellers.name as seller_name', 'sellers.id as seller_ref')
            ->first();

        $lines = DB::table('order_lines')->where('order_id', $orderId)->orderBy('position')->get();
        $deadline = new DateTimeImmutable($row->payment_deadline);
        $open = in_array($row->status, [OrderStatus::Placed->value, OrderStatus::AwaitingPayment->value], true);

        return [
            'order' => [
                'id' => (int) $row->id,
                'status' => $row->status,
                'status_label' => OrderStatusLabel::for($row->status),
                'seller' => ['id' => (int) $row->seller_ref, 'name' => $row->seller_name],
                'lines' => $lines->map(fn (object $line): array => [
                    'offer_id' => (int) $line->offer_id,
                    'product_name' => $line->product_name,
                    'unit_price_cents' => (int) $line->unit_price_cents,
                    'quantity' => (int) $line->quantity,
                ])->all(),
                'total_cents' => (int) $row->total_cents,
                'payment_deadline' => $deadline->format(DATE_ATOM),
                'seconds_to_deadline' => $open ? max(0, $deadline->getTimestamp() - $now->getTimestamp()) : 0,
                'refunds' => DB::table('order_refunds')->where('order_id', $orderId)->orderBy('id')->get()
                    ->map(fn (object $refund): array => [
                        'amount_cents' => (int) $refund->amount_cents,
                        'status' => $refund->status,
                        'reason' => $refund->reason,
                    ])->all(),
                'tracking_number' => $row->tracking_number,
                'rejection_label' => $this->rejectionLabel($row, $lines),
                'cancellation_label' => $this->cancellationLabel($row),
            ],
            'timeline' => DB::table('order_events')->where('order_id', $orderId)->orderBy('id')->get()
                ->map(fn (object $event): array => [
                    'id' => (int) $event->id,
                    'type' => $event->event_type,
                    'occurred_at' => (new DateTimeImmutable($event->occurred_at))->format(DATE_ATOM),
                ])->all(),
        ];
    }

    private function rejectionLabel(object $row, Collection $lines): ?string
    {
        if ($row->rejection_reason === null) {
            return null;
        }

        $product = $lines->firstWhere('offer_id', $row->rejection_offer_id)?->product_name ?? 'produkt';

        return match ($row->rejection_reason) {
            'out_of_stock' => 'Zabrakło towaru: '.$product,
            'price_changed' => 'Cena się zmieniła: '.$product,
            'offer_unavailable' => 'Oferta niedostępna: '.$product,
            default => 'Zamówienie odrzucone',
        };
    }

    private function cancellationLabel(object $row): ?string
    {
        return match ($row->cancelled_by) {
            null => null,
            'buyer' => 'Anulowałeś zamówienie',
            default => 'Sprzedawca anulował zamówienie'.($row->cancellation_reason === null ? '' : ': '.$row->cancellation_reason),
        };
    }
}
