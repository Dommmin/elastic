<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autocomplete — zwykły `fetch`, NIE Inertia (docs/02-APLIKACJE.md, sekcja 8:
 * Inertia zawsze przeładowuje propsy strony i wpisuje wizytę do historii,
 * czego podpowiedzi przy każdym znaku nie powinny robić). Obrona przed
 * wyścigiem żądań (AbortController + numer sekwencyjny) jest po stronie
 * frontu (`resources/js/components/search/SearchBar.vue`) — ten endpoint
 * jest bezstanowy i nie wie nic o kolejności żądań.
 */
class SuggestController extends Controller
{
    public function __invoke(Request $request, ProductSearchService $service): JsonResponse
    {
        $suggestions = $service->suggest(
            (string) $request->query('q', ''),
            limit: min(10, max(1, (int) $request->query('limit', 8))),
        );

        return response()->json(['suggestions' => $suggestions]);
    }
}
