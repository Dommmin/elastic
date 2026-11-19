<?php

namespace App\Services\Search;

use App\Models\Product;

/**
 * Jedno miejsce budujące projekcję produktu (kształt `_source` w ES):
 * używa go endpoint read-back dla search-service oraz `search:verify-convergence`,
 * żeby weryfikacja porównywała z DOKŁADNIE tym, co trafia do indeksu.
 */
class ProductProjectionBuilder
{
    /** @return array<string, mixed>|null null, gdy produkt nie istnieje */
    public function build(int $id, int $projectedAtUs): ?array
    {
        $product = Product::with(['brand', 'category', 'listedOffers' => fn ($query) => $query->withAvailable(), 'listedOffers.seller'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->find($id);

        if ($product === null) {
            return null;
        }

        $offers = $product->listedOffers;

        return [
            'id' => $product->id,
            'projected_at_us' => $projectedAtUs,
            'version' => $product->version,
            'name' => $product->name,
            'description' => $product->description,
            'brand' => $product->brand?->name,
            'category' => $product->category ? [
                'id' => (string) $product->category->id,
                'path' => $product->category->path,
            ] : null,
            'attributes' => $product->attributes ?? [],
            'price_min' => $offers->isNotEmpty() ? $offers->min('price_cents') : null,
            'price_max' => $offers->isNotEmpty() ? $offers->max('price_cents') : null,
            'in_stock' => $offers->contains(fn ($offer) => $offer->available > 0),
            'rating_avg' => $product->reviews_avg_rating !== null
                ? round((float) $product->reviews_avg_rating, 2)
                : null,
            'rating_count' => $product->reviews_count,
            'offers' => $offers->map(fn ($offer) => [
                'offer_id' => (string) $offer->id,
                'seller_id' => (string) $offer->seller_id,
                'seller' => $offer->seller->name,
                'price' => $offer->price_cents,
                // DLACZEGO `available`: wyszukiwarka pokazuje to, co da się kupić, nie deklarację sprzedawcy.
                'stock' => (int) $offer->available,
                'location' => $offer->seller->lat !== null && $offer->seller->lon !== null
                    ? ['lat' => (float) $offer->seller->lat, 'lon' => (float) $offer->seller->lon]
                    : null,
            ])->values(),
            'created_at' => $product->created_at?->toIso8601String(),
            'indexed_at' => now()->toIso8601String(),
        ];
    }
}
