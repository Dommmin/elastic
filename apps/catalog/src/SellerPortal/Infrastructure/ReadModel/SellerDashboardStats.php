<?php

namespace SellerPortal\Infrastructure\ReadModel;

use Illuminate\Support\Facades\DB;

/**
 * Liczniki na pulpicie sprzedawcy — te same definicje co filtry
 * `SellerOfferList`, żeby kliknięcie w licznik pokazało tyle ofert, ile obiecał.
 */
final class SellerDashboardStats
{
    /**
     * @return array{draft: int, published: int, suspended: int, withdrawn: int, sold_out: int, pending_price: int, orders_to_ship: int}
     */
    public function forSeller(int $sellerId): array
    {
        $byStatus = DB::table('offers')
            ->where('seller_id', $sellerId)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS total')
            ->pluck('total', 'status');

        $extra = DB::table('offers')
            ->where('seller_id', $sellerId)
            ->selectRaw("SUM(CASE WHEN offers.status = 'published' AND ".SellerOfferList::AVAILABLE_SQL.' = 0 THEN 1 ELSE 0 END) AS sold_out')
            ->selectRaw('SUM(CASE WHEN pending_price_cents IS NOT NULL THEN 1 ELSE 0 END) AS pending_price')
            ->first();

        return [
            'draft' => (int) ($byStatus['draft'] ?? 0),
            'published' => (int) ($byStatus['published'] ?? 0),
            'suspended' => (int) ($byStatus['suspended'] ?? 0),
            'withdrawn' => (int) ($byStatus['withdrawn'] ?? 0),
            'sold_out' => (int) ($extra->sold_out ?? 0),
            'pending_price' => (int) ($extra->pending_price ?? 0),
            // DLACZEGO zapytanie po tabeli `orders`, a nie import z Ordering: portal nie zna
            // tego kontekstu (granica warstw), a licznik to czysty odczyt.
            'orders_to_ship' => DB::table('orders')->where('seller_id', $sellerId)->where('status', 'paid')->count(),
        ];
    }
}
