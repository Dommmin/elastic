<?php

namespace Ordering\Infrastructure;

use App\Models\Offer;
use Ordering\Application\Port\OfferSnapshot;
use Ordering\Application\Port\OfferSnapshots;
use Ordering\Domain\OfferId;
use Ordering\Domain\ProductName;
use Ordering\Domain\SellerId;
use SharedKernel\Currency;
use SharedKernel\Money;

/**
 * Odczyt ofert dla składania zamówienia jednym zapytaniem (oferta, sprzedawca,
 * nazwa produktu), bez ładowania agregatów portalu.
 *
 * DLACZEGO `listed` liczy scope `listed()` na modelu `Offer`, a nie kopia
 * warunku: „oferta do kupienia” (opublikowana i z wolnym towarem) ma jedną
 * definicję, tę samą co wyszukiwarka i karta oferty.
 */
final class DbOfferSnapshots implements OfferSnapshots
{
    public function forOffers(array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }

        $ids = array_map(static fn (OfferId $id): int => $id->value(), $offerIds);
        $listed = Offer::query()->listed()->whereIn('offers.id', $ids)->select('offers.id');

        $rows = Offer::query()
            ->join('sellers', 'sellers.id', '=', 'offers.seller_id')
            ->join('products', 'products.id', '=', 'offers.product_id')
            ->whereIn('offers.id', $ids)
            ->select('offers.id', 'offers.seller_id', 'offers.price_cents', 'offers.currency', 'sellers.user_id as seller_user_id', 'products.name as product_name')
            ->selectRaw('offers.id in ('.$listed->toSql().') as is_listed', $listed->getBindings())
            ->get();

        $snapshots = [];

        foreach ($rows as $row) {
            $snapshots[(int) $row->id] = new OfferSnapshot(
                OfferId::fromInt((int) $row->id),
                SellerId::fromInt((int) $row->seller_id),
                (int) $row->seller_user_id,
                ProductName::fromString($row->product_name),
                Money::fromCents((int) $row->price_cents, Currency::from($row->currency)),
                (bool) $row->is_listed,
            );
        }

        return $snapshots;
    }
}
