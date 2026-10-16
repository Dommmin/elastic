<?php

namespace Ordering\Infrastructure\ReadModel;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Model odczytu listy „Moje zamówienia”: płaskie wiersze bez agregatów.
 *
 * DLACZEGO bez `Order`: lista nie podejmuje decyzji, więc nie ładuje agregatu
 * ani pozycji N razy; indeks `(buyer_user_id, id)` obsługuje całe zapytanie.
 */
final class BuyerOrderList
{
    /**
     * @return list<array{id: int, status: string, status_label: string, seller: string, total_cents: int, placed_at: string, lines_count: int, first_product: string|null}>
     */
    public function forBuyer(int $userId): array
    {
        $rows = DB::table('orders')
            ->join('sellers', 'sellers.id', '=', 'orders.seller_id')
            ->where('orders.buyer_user_id', $userId)
            ->orderByDesc('orders.id')
            ->select('orders.id', 'orders.status', 'orders.total_cents', 'orders.placed_at', 'sellers.name as seller_name')
            ->get();

        $lines = DB::table('order_lines')
            ->whereIn('order_id', $rows->pluck('id'))
            ->orderBy('position')
            ->get(['order_id', 'product_name'])
            ->groupBy('order_id');

        return $rows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'status' => $row->status,
            'status_label' => OrderStatusLabel::for($row->status),
            'seller' => $row->seller_name,
            'total_cents' => (int) $row->total_cents,
            'placed_at' => (new DateTimeImmutable($row->placed_at))->format(DATE_ATOM),
            'lines_count' => count($lines[$row->id] ?? []),
            'first_product' => ($lines[$row->id] ?? collect())->first()?->product_name,
        ])->all();
    }
}
