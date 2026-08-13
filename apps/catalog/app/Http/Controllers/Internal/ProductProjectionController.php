<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

/**
 * "Read-back API" (docs/02-APLIKACJE.md, sekcja 7.1, wariant B) — jedyny
 * sposób, w jaki search-service (Symfony) dowiaduje się, jak wygląda
 * produkt, żeby zbudować z niego dokument Elasticsearcha. Symfony NIE ma
 * bezpośredniego dostępu do bazy `catalog` (D-02: kontraktem między
 * serwisami są zdarzenia i to API, nigdy współdzielony schemat bazy).
 *
 * Dostępne TYLKO w sieci dockerowej (brak publicznego routingu na ten
 * prefix — patrz routes/api.php). To nie jest zabezpieczenie samo w sobie,
 * tylko topologia; prawdziwa izolacja przyjdzie z API keys w ETAPIE 12.
 *
 * Kształt odpowiedzi jest zaprojektowany pod mapowanie `products-v1`
 * (infra/elasticsearch/mappings/products-v1.json) — search-service
 * w większości przypadków tylko przepisuje te pola do `_source`.
 */
class ProductProjectionController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $product = Product::with(['brand', 'category', 'activeOffers.seller'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->findOrFail($id);

        $offers = $product->activeOffers;

        // JSON_PRESERVE_ZERO_FRACTION: bez tej flagi PHP koduje float 4.0
        // jako "4", nie "4.0". Po drugiej stronie (search-service, Symfony)
        // json_decode("4") daje int, nie float — cicha utrata typu w kontrakcie
        // między serwisami. Drobne, ale dokładnie ten rodzaj błędu, który nie
        // wybuchnie od razu, tylko któregoś dnia da dziwny wynik w agregacji.
        return response()->json([
            'id' => $product->id,
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
            'in_stock' => $offers->contains(fn ($offer) => $offer->stock > 0),
            'rating_avg' => $product->reviews_avg_rating !== null
                ? round((float) $product->reviews_avg_rating, 2)
                : null,
            'rating_count' => $product->reviews_count,
            'offers' => $offers->map(fn ($offer) => [
                'offer_id' => (string) $offer->id,
                'seller_id' => (string) $offer->seller_id,
                'seller' => $offer->seller->name,
                'price' => $offer->price_cents,
                'stock' => $offer->stock,
                'location' => $offer->seller->lat !== null && $offer->seller->lon !== null
                    ? ['lat' => (float) $offer->seller->lat, 'lon' => (float) $offer->seller->lon]
                    : null,
            ])->values(),
            'created_at' => $product->created_at?->toIso8601String(),
            'indexed_at' => now()->toIso8601String(),
        ], options: JSON_PRESERVE_ZERO_FRACTION);
    }
}
