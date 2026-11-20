<?php

namespace App\Http\Controllers\Shop;

use App\Analytics\AnalyticsRecorder;
use App\Analytics\SearchContextStore;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AnalyticsSession;
use App\Http\Requests\Shop\AddToCartRequest;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Strona produktu dla kupującego: oferty `listed` czytane z bazy (nie z ES),
 * bo cena i dostępność muszą być aktualne w chwili dodania do koszyka.
 */
class ProductPageController extends Controller
{
    public function __construct(
        private readonly AnalyticsRecorder $recorder,
        private readonly SearchContextStore $contexts,
    ) {}

    public function show(Request $request, Product $product): Response
    {
        $userId = $request->user()?->getAuthIdentifier();
        $searchId = self::searchId($request->query('sid'));

        $this->recordClick($request, $product, $searchId);

        $offers = Offer::query()
            ->withAvailable()
            ->listed()
            ->where('product_id', $product->id)
            ->with('seller:id,name,user_id')
            ->orderBy('price_cents')
            ->orderBy('offers.id')
            ->get()
            ->map(fn (Offer $offer): array => [
                'id' => $offer->id,
                'seller' => ['id' => $offer->seller->id, 'name' => $offer->seller->name],
                'price_cents' => $offer->price_cents,
                'condition' => $offer->condition,
                'shipping_days' => $offer->shipping_days,
                'available' => (int) $offer->available,
                'max_quantity' => AddToCartRequest::maxQuantity($offer),
                'is_own' => $userId !== null && $offer->seller->user_id === $userId,
            ])
            ->all();

        return Inertia::render('shop/Product', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'brand' => $product->brand?->name,
            ],
            'offers' => $offers,
            // Przenoszony do formularza koszyka, żeby `user.added_to_cart` znał wyszukiwanie.
            'searchId' => $searchId,
        ]);
    }

    /**
     * Kliknięcie to wejście na stronę z `?sid=` (linki z wyników). Zdarzenie
     * powstaje tylko wtedy, gdy kontekst wyszukiwania jest w Redisie i zawiera
     * ten produkt — inaczej każdy mógłby dopisać kliknięcie dowolnemu produktowi,
     * a IPS (waga 1/p(k)) wzmocniłby fałszywe kliknięcia na dalekich pozycjach.
     * Pozycja to indeks w kontekście + 1; `pos` z URL-a jest ignorowane. Produkt
     * spoza top-20 kontekstu (np. druga strona wyników) nie daje zdarzenia.
     * Odświeżenie nie dubluje kliknięcia: `dedup_key` jest stały, a search-service
     * używa go jako `_id` dokumentu (drugi zapis dostaje 409).
     */
    private function recordClick(Request $request, Product $product, ?string $searchId): void
    {
        if ($searchId === null) {
            return;
        }

        $context = $this->contexts->get($searchId);
        $index = $context === null ? false : array_search($product->id, $context['results'], true);
        if ($index === false) {
            return;
        }

        $data = [
            'search_id' => $searchId,
            'query' => $context['query'],
            'product_id' => $product->id,
            'position' => $index + 1,
            'dedup_key' => "click:{$searchId}:{$product->id}",
        ];

        if ($request->user() !== null) {
            $data['user_id'] = $request->user()->getAuthIdentifier();
        }

        $this->recorder->record('user.clicked_result', AnalyticsSession::currentId($request), $data);
    }

    /**
     * `sid` z URL-a: alfabet ULID/UUID do 64 znaków, inaczej null (nie ufamy
     * wejściu, a wartość trafia do klucza w Redisie).
     */
    private static function searchId(mixed $value): ?string
    {
        return SearchContextStore::isValidSearchId($value) ? $value : null;
    }
}
