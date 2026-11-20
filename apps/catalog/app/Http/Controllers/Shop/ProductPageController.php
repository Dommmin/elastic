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
     * Kliknięcie to wejście na stronę z `?sid=&pos=` (linki z wyników). Bez
     * poprawnego `sid` i `pos` (liczba całkowita >= 1) zdarzenia nie ma, ale
     * strona działa. Odświeżenie nie dubluje kliknięcia: `dedup_key` jest stały,
     * a search-service używa go jako `_id` dokumentu (drugi zapis dostaje 409).
     */
    private function recordClick(Request $request, Product $product, ?string $searchId): void
    {
        $position = $request->query('pos');

        if ($searchId === null || ! is_string($position) || ! ctype_digit($position) || strlen($position) > 4 || (int) $position < 1) {
            return;
        }

        $data = [
            'search_id' => $searchId,
            'product_id' => $product->id,
            'position' => (int) $position,
            'dedup_key' => "click:{$searchId}:{$product->id}",
        ];

        // Brak wpisu w Redisie (wygasł) = zdarzenie bez frazy.
        $context = $this->contexts->get($searchId);
        if ($context !== null) {
            $data['query'] = $context['query'];
        }

        if ($request->user() !== null) {
            $data['user_id'] = $request->user()->getAuthIdentifier();
        }

        $this->recorder->record('user.clicked_result', AnalyticsSession::currentId($request), $data);
    }

    /**
     * `sid` z URL-a: niepusty napis do 64 znaków, inaczej null (nie ufamy wejściu).
     */
    private static function searchId(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 64 ? $value : null;
    }
}
