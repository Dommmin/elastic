<?php

namespace SellerPortal\Infrastructure\ReadModel;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Wybór produktu przy zakładaniu oferty.
 *
 * DLACZEGO Postgres, a nie Elasticsearch: zapis oferty nie może zależeć od
 * dostępności wyszukiwarki, a wystarczy prosty prefiks EAN albo fragment nazwy.
 * DLACZEGO pomijamy produkty z własną żywą ofertą: szkic i tak by odbił
 * `LiveOfferAlreadyExists`, więc nie podsuwamy ślepej uliczki.
 */
final class ProductLookup
{
    private const int LIMIT = 10;

    /**
     * @return list<array{id: int, name: string, brand: string|null, ean: string|null}>
     */
    public function search(int $sellerId, string $query): array
    {
        $query = trim($query);

        return DB::table('products')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->when(
                preg_match('/^\d{8,13}$/', $query) === 1,
                fn (Builder $builder) => $builder->where('products.ean', 'like', $query.'%'),
                fn (Builder $builder) => $builder->whereLike('products.name', '%'.self::escapeLike($query).'%'),
            )
            ->whereNotExists(fn (Builder $offers) => $offers
                ->selectRaw('1')
                ->from('offers')
                ->whereColumn('offers.product_id', 'products.id')
                ->where('offers.seller_id', $sellerId)
                ->where('offers.status', '<>', 'withdrawn'))
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->limit(self::LIMIT)
            ->get(['products.id', 'products.name', 'brands.name as brand', 'products.ean'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'brand' => $row->brand,
                'ean' => $row->ean,
            ])
            ->all();
    }

    /**
     * `%` i `_` wpisane przez użytkownika mają znaczyć siebie, a nie „cokolwiek”.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
