<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pomocnicze endpointy search-service (ETAP 10): bramka jakości reindeksu
 * porównuje liczbę dokumentów w nowym indeksie z `count`, a ścieżka
 * "odbuduj z Postgresa" wędruje po `ids` i dla każdego ID pobiera projekcję.
 *
 * "Produkt" to każdy wiersz `products` — `ProductProjectionController` zwraca
 * projekcję dla każdego istniejącego produktu (oferty filtruje osobno).
 * Ta sama (brak) autoryzacji co reszta prefiksu `internal`, patrz routes/api.php.
 */
class ProductIdsController extends Controller
{
    private const DEFAULT_LIMIT = 1000;

    public function count(): JsonResponse
    {
        return response()->json(['count' => Product::query()->count()]);
    }

    /**
     * Keyset po `id` rosnąco (nie OFFSET): strona kosztuje tyle samo na
     * 300 tys. wierszy co na 10, a równoległe INSERT-y nie tworzą dubli ani luk.
     */
    public function ids(Request $request): JsonResponse
    {
        $data = $request->validate([
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'between:1,5000'],
        ]);

        $after = (int) ($data['after'] ?? 0);
        $limit = (int) ($data['limit'] ?? self::DEFAULT_LIMIT);

        // limit + 1: dodatkowy wiersz mówi, czy jest kolejna strona, bez drugiego zapytania.
        $ids = Product::query()
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit + 1)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $hasMore = count($ids) > $limit;
        $page = array_slice($ids, 0, $limit);

        return response()->json([
            'ids' => $page,
            'next_after' => $hasMore ? end($page) : null,
        ]);
    }
}
