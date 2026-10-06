<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductSearchService;
use App\Services\Search\SearchCriteria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Czysty JSON, ta sama logika co `App\Http\Controllers\SearchController`
 * (D-09) — dla klientów zewnętrznych i telemetrii, nie dla przeglądarki
 * przez Inertię (docs/02-APLIKACJE.md, sekcja 8, "JSON API").
 */
class SearchController extends Controller
{
    public function index(Request $request, ProductSearchService $service): JsonResponse
    {
        $criteria = SearchCriteria::fromRequest($request);
        $result = $service->search($criteria);

        return response()->json([
            ...$result->toArray(),
            'filters' => $criteria->toArray(),
        ]);
    }
}
