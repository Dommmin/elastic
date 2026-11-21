<?php

namespace App\Http\Controllers\Api;

use App\Analytics\SearchedEventEmitter;
use App\Http\Controllers\Controller;
use App\Services\ProductSearchService;
use App\Services\Search\SearchCriteria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Czysty JSON, ta sama logika co `App\Http\Controllers\SearchController`
 * (D-09) — dla klientów zewnętrznych i telemetrii, nie dla przeglądarki
 * przez Inertię (docs/02-APLIKACJE.md, sekcja 8, "JSON API").
 */
class SearchController extends Controller
{
    public function index(Request $request, ProductSearchService $service, SearchedEventEmitter $analytics): JsonResponse
    {
        $criteria = SearchCriteria::fromRequest($request);
        $result = $service->search($criteria);

        // ETAP 12: tylko pierwsza strona (bez kursora) jest "wyszukaniem".
        if ($criteria->cursor === null) {
            $analytics->emit($request, (string) Str::ulid(), $criteria, $result);
        }

        return response()->json([
            ...$result->toArray(),
            'filters' => $criteria->toArray(),
        ]);
    }
}
