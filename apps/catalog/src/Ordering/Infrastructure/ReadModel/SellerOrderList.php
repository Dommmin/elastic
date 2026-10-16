<?php

namespace Ordering\Infrastructure\ReadModel;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Ordering\Domain\OrderStatus;

/**
 * Model odczytu listy zamówień sprzedawcy: „Do wysłania” (tylko opłacone) albo wszystkie.
 *
 * DLACZEGO bez `Order`: lista nie podejmuje decyzji; filtrujemy po `seller_id`
 * z sesji sprzedawcy, więc cudzych zamówień tu nie ma. Zamówienia, które
 * kupujący jeszcze zbiera do opłacenia, sprzedawca widzi dopiero w „Wszystkie”.
 */
final class SellerOrderList
{
    public const string TO_SHIP = 'to_ship';

    public const string ALL = 'all';

    /**
     * @return list<array{id: int, status: string, status_label: string, buyer: string, total_cents: int, placed_at: string, lines_count: int, first_product: string|null}>
     */
    public function forSeller(int $sellerId, string $tab): array
    {
        $query = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.buyer_user_id')
            ->where('orders.seller_id', $sellerId)
            ->orderByDesc('orders.id')
            ->select('orders.id', 'orders.status', 'orders.total_cents', 'orders.placed_at', 'users.name as buyer_name');

        if ($tab === self::TO_SHIP) {
            $query->where('orders.status', OrderStatus::Paid->value);
        }

        $rows = $query->get();

        $lines = DB::table('order_lines')
            ->whereIn('order_id', $rows->pluck('id'))
            ->orderBy('position')
            ->get(['order_id', 'product_name'])
            ->groupBy('order_id');

        return $rows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'status' => $row->status,
            'status_label' => OrderStatusLabel::for($row->status),
            'buyer' => $row->buyer_name,
            'total_cents' => (int) $row->total_cents,
            'placed_at' => (new DateTimeImmutable($row->placed_at))->format(DATE_ATOM),
            'lines_count' => count($lines[$row->id] ?? []),
            'first_product' => ($lines[$row->id] ?? collect())->first()?->product_name,
        ])->all();
    }
}
