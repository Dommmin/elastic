<?php

namespace Ordering\Infrastructure\ReadModel;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Model odczytu szczegółów zamówienia dla sprzedawcy: pozycje, kupujący,
 * zwroty, numer przesyłki i oś czasu.
 *
 * Sprzedawcę sprawdza wcześniej `OrderAccess::forSeller`; ten model zakłada,
 * że `$orderId` jest jego. DLACZEGO bez terminu płatności: sprzedawca widzi
 * zamówienie dopiero do realizacji, odliczanie dotyczy kupującego.
 */
final class SellerOrderDetails
{
    /**
     * @return array{order: array<string, mixed>, timeline: list<array{id: int, type: string, occurred_at: string}>}
     */
    public function for(int $orderId): array
    {
        $row = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.buyer_user_id')
            ->where('orders.id', $orderId)
            ->select('orders.*', 'users.name as buyer_name')
            ->first();

        return [
            'order' => [
                'id' => (int) $row->id,
                'status' => $row->status,
                'status_label' => OrderStatusLabel::for($row->status),
                'buyer' => $row->buyer_name,
                'placed_at' => (new DateTimeImmutable($row->placed_at))->format(DATE_ATOM),
                'lines' => DB::table('order_lines')->where('order_id', $orderId)->orderBy('position')->get()
                    ->map(fn (object $line): array => [
                        'offer_id' => (int) $line->offer_id,
                        'product_name' => $line->product_name,
                        'unit_price_cents' => (int) $line->unit_price_cents,
                        'quantity' => (int) $line->quantity,
                    ])->all(),
                'total_cents' => (int) $row->total_cents,
                'refunds' => DB::table('order_refunds')->where('order_id', $orderId)->orderBy('id')->get()
                    ->map(fn (object $refund): array => [
                        'amount_cents' => (int) $refund->amount_cents,
                        'status' => $refund->status,
                    ])->all(),
                'tracking_number' => $row->tracking_number,
                'cancellation_label' => match ($row->cancelled_by) {
                    null => null,
                    'buyer' => 'Kupujący anulował zamówienie',
                    default => 'Anulowałeś zamówienie'.($row->cancellation_reason === null ? '' : ': '.$row->cancellation_reason),
                },
            ],
            'timeline' => DB::table('order_events')->where('order_id', $orderId)->orderBy('id')->get()
                ->map(fn (object $event): array => [
                    'id' => (int) $event->id,
                    'type' => $event->event_type,
                    'occurred_at' => (new DateTimeImmutable($event->occurred_at))->format(DATE_ATOM),
                ])->all(),
        ];
    }
}
