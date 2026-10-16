<?php

namespace SellerPortal\Infrastructure\ReadModel;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Strona szczegółów oferty sprzedawcy: wiersz oferty z nazwą produktu
 * i oś czasu zdarzeń z outboxu.
 *
 * DLACZEGO oś czasu z outboxu, a nie z osobnej historii: outbox to dokładnie
 * to, co poszło do wyszukiwarki, więc „opublikowane” na osi znaczy „w drodze
 * do ES”, tak jak w panelowym `EventTrace`.
 */
final class SellerOfferDetails
{
    private const int TIMELINE_LIMIT = 20;

    /**
     * Właściciela sprawdza wołający (warstwa aplikacji); tu filtr po
     * sprzedawcy to tylko druga linia obrony przed pomyłką w kontrolerze.
     *
     * @return array{id: int, product: array{id: int, name: string}, price_cents: int, currency: string, stock: int, reserved: int, available: int, condition: string, shipping_days: int, status: string, is_sold_out: bool, is_listed: bool, suspension: array{kind: string, note: string|null}|null, pending_price: array{price_cents: int, requested_at: string}|null, version: int}|null
     */
    public function find(int $sellerId, int $offerId): ?array
    {
        $row = DB::table('offers')
            ->join('products', 'products.id', '=', 'offers.product_id')
            ->where('offers.id', $offerId)
            ->where('offers.seller_id', $sellerId)
            ->first([
                'offers.id', 'offers.product_id', 'products.name as product_name', 'offers.price_cents', 'offers.currency',
                'offers.stock', 'offers.condition', 'offers.shipping_days', 'offers.status', 'offers.suspension_kind',
                'offers.suspension_note', 'offers.pending_price_cents', 'offers.pending_requested_at', 'offers.version',
            ]);

        if ($row === null) {
            return null;
        }

        // Aktywne rezerwacje to jedyne, które zmniejszają dostępność; wiersze
        // `confirmed`/`released` zostają w tabeli tylko jako pamięć idempotencji.
        $reserved = (int) DB::table('offer_reservations')
            ->where('offer_id', $offerId)
            ->where('status', 'active')
            ->sum('quantity');
        $stock = (int) $row->stock;

        $published = $row->status === 'published';

        return [
            'id' => (int) $row->id,
            'product' => ['id' => (int) $row->product_id, 'name' => (string) $row->product_name],
            'price_cents' => (int) $row->price_cents,
            'currency' => (string) $row->currency,
            'stock' => $stock,
            'reserved' => $reserved,
            'available' => $stock - $reserved,
            'condition' => (string) $row->condition,
            'shipping_days' => (int) $row->shipping_days,
            'status' => (string) $row->status,
            'is_sold_out' => $published && $stock - $reserved === 0,
            'is_listed' => $published && $stock - $reserved > 0,
            'suspension' => $row->suspension_kind === null ? null : [
                'kind' => (string) $row->suspension_kind,
                'note' => $row->suspension_note,
            ],
            'pending_price' => $row->pending_price_cents === null ? null : [
                'price_cents' => (int) $row->pending_price_cents,
                'requested_at' => self::iso($row->pending_requested_at),
            ],
            'version' => (int) $row->version,
        ];
    }

    /**
     * Najnowsze pierwsze.
     *
     * @return list<array{id: int, type: string, occurred_at: string, published: bool}>
     */
    public function timeline(int $offerId): array
    {
        return DB::table('outbox')
            ->where('aggregate_type', 'offer')
            ->where('aggregate_id', (string) $offerId)
            ->orderByDesc('id')
            ->limit(self::TIMELINE_LIMIT)
            ->get(['id', 'event_type', 'occurred_at', 'published_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'type' => (string) $row->event_type,
                'occurred_at' => self::iso($row->occurred_at),
                'published' => $row->published_at !== null,
            ])
            ->all();
    }

    private static function iso(string $value): string
    {
        return Carbon::parse($value)->toIso8601ZuluString('microsecond');
    }
}
