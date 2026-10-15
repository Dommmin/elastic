<?php

namespace SellerPortal\Infrastructure\ReadModel;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lista ofert zalogowanego sprzedawcy z filtrem statusu.
 *
 * DLACZEGO filtry `sold_out` i `pending_price` obok statusów agregatu:
 * sprzedawca myśli „co wymaga mojej uwagi” (brak towaru, cena u moderatora),
 * a nie „jaki jest status w maszynie stanów”. Definicje są zgodne
 * z `Offer::isSoldOut()` i `Offer::pendingPriceChange()`.
 */
final class SellerOfferList
{
    public const array FILTERS = ['draft', 'published', 'sold_out', 'suspended', 'withdrawn', 'pending_price'];

    /**
     * @return LengthAwarePaginator<int, array{id: int, product: array{id: int, name: string}, price_cents: int, currency: string, stock: int, status: string, is_listed: bool, is_sold_out: bool, pending_price_cents: int|null, updated_at: string|null}>
     */
    public function paginate(int $sellerId, ?string $filter, int $perPage = 25): LengthAwarePaginator
    {
        return DB::table('offers')
            ->join('products', 'products.id', '=', 'offers.product_id')
            ->where('offers.seller_id', $sellerId)
            ->when($filter !== null, fn (Builder $query) => self::applyFilter($query, $filter))
            ->orderByDesc('offers.id')
            ->select([
                'offers.id', 'offers.product_id', 'products.name as product_name', 'offers.price_cents',
                'offers.currency', 'offers.stock', 'offers.status', 'offers.pending_price_cents', 'offers.updated_at',
            ])
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (object $row): array => [
                'id' => (int) $row->id,
                'product' => ['id' => (int) $row->product_id, 'name' => (string) $row->product_name],
                'price_cents' => (int) $row->price_cents,
                'currency' => (string) $row->currency,
                'stock' => (int) $row->stock,
                'status' => (string) $row->status,
                'is_listed' => $row->status === 'published' && (int) $row->stock > 0,
                'is_sold_out' => $row->status === 'published' && (int) $row->stock === 0,
                'pending_price_cents' => $row->pending_price_cents === null ? null : (int) $row->pending_price_cents,
                'updated_at' => $row->updated_at,
            ]);
    }

    private static function applyFilter(Builder $query, string $filter): void
    {
        match ($filter) {
            'sold_out' => $query->where('offers.status', 'published')->where('offers.stock', 0),
            'pending_price' => $query->whereNotNull('offers.pending_price_cents'),
            default => $query->where('offers.status', $filter),
        };
    }
}
