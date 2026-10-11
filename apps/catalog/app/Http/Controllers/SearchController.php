<?php

namespace App\Http\Controllers;

use App\Services\CategoryLabels;
use App\Services\ProductSearchService;
use App\Services\Search\SearchCriteria;
use App\Services\Search\SearchResult;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\ProvidesScrollMetadata;
use Inertia\Response;

/**
 * D-09: kontroler jest cienkim adapterem nad `ProductSearchService` — cała
 * logika (query DSL, facety, sortowanie) żyje w serwisie, dokładnie tak samo
 * używanym przez `Api\SearchController` (JSON) i `search:eval`.
 *
 * Każdy prop jest LENIWY (closure albo typ propa Inertii) — closure wykonuje
 * się tylko wtedy, gdy klient faktycznie prosi o ten klucz. To jest cały
 * sens partial reloadów w ETAPIE 7 i da się to udowodnić w slowlogu ES
 * (`make es-slowlog-on`, docs/blog/etap-07-wyszukiwarka.md):
 *
 *  - pierwsze wejście            → search() [wyniki + facety], bez histogramu
 *  - auto-request po histogram   → TYLKO priceHistogram()  (wcześniej, gdy
 *                                  `results` było zwykłą tablicą, ten request
 *                                  liczył też całe search() od nowa — na marne)
 *  - klik w facet / sort / fraza → search(), bez histogramu
 *  - przewinięcie (scroll)       → search() BEZ agregacji (patrz serwis)
 */
class SearchController extends Controller
{
    public function index(Request $request, ProductSearchService $service): Response
    {
        $criteria = SearchCriteria::fromRequest($request);

        // `results` i `facets` pochodzą z JEDNEGO zapytania do ES. Memoizacja
        // w zmiennej lokalnej (nie w polu klasy ani `once()`), bo pod Octane
        // kontroler może żyć dłużej niż jedno żądanie — stan per-request musi
        // umrzeć razem z requestem (docs/06, D-07b: wycieki stanu w worker mode).
        $result = null;
        $search = function () use (&$result, $service, $criteria): SearchResult {
            return $result ??= $service->search($criteria);
        };

        return Inertia::render('Search', [
            // Inertia::scroll() to serwerowa strona komponentu <InfiniteScroll>:
            // kolejna strona przychodzi jako `?cursor=...` i jest DOKLEJANA do
            // `results.data` (nagłówek merge-intent wysyła komponent). Zmiana
            // filtrów wysyła `reset: ['results']` (Search.vue) — wtedy lista
            // jest ZASTĘPOWANA, nie doklejana.
            'results' => Inertia::scroll(
                fn () => [
                    'data' => $search()->items,
                    'total' => $search()->total,
                    'took_ms' => $search()->tookMs,
                ],
                wrapper: 'data',
                metadata: fn () => self::cursorMetadata($criteria->cursor, $search()->nextCursor),
            )->matchOn('data.id'),
            'facets' => fn () => self::labelCategoryFacets($search()->facets),
            'filters' => $criteria->toArray(),
            'priceHistogram' => Inertia::defer(fn () => $service->priceHistogram($criteria)),
        ]);
    }

    /**
     * ES zna tylko `category.path` ("3.17") — to klucz filtra i tak ma zostać.
     * Nazwy żyją w Postgresie katalogu, więc doklejamy `label` ("Sport › Bieganie")
     * jednym zapytaniem o wszystkie ID z widocznych bucketów. Brak kategorii
     * w bazie (np. usunięta, a indeks jeszcze nie przeliczony) → zostaje ścieżka.
     *
     * @param  array<string, mixed>  $facets
     * @return array<string, mixed>
     */
    private static function labelCategoryFacets(array $facets): array
    {
        $buckets = $facets['category'] ?? [];

        if ($buckets === []) {
            return $facets;
        }

        $labels = CategoryLabels::forPaths(array_map(fn (array $bucket): string => (string) $bucket['key'], $buckets));

        $facets['category'] = array_map(function (array $bucket) use ($labels): array {
            $bucket['label'] = $labels[(string) $bucket['key']];

            return $bucket;
        }, $buckets);

        // Po etykiecie, nie po liczności — podkategorie jednego działu muszą
        // stać obok siebie, bo UI grupuje je pod nagłówkiem działu.
        // Separator \x01 zamiast " › ", żeby "Sport X" nie wcisnął się między
        // "Sport" a jego podkategorie (spacja sortuje się przed "›").
        $sortKey = fn (array $bucket): string => str_replace(' › ', "\x01", $bucket['label']);
        usort($facets['category'], fn (array $a, array $b): int => strcmp($sortKey($a), $sortKey($b)));

        return $facets;
    }

    /**
     * Inertia domyślnie czyta metadane stronicowania z paginatora Laravela
     * (numer strony). `search_after` nie ma numerów stron — ma nieprzezroczysty
     * kursor, więc podajemy własne metadane: "następna strona" = kursor, a
     * "poprzedniej" nie ma (search_after działa tylko do przodu).
     */
    private static function cursorMetadata(?string $currentCursor, ?string $nextCursor): ProvidesScrollMetadata
    {
        return new readonly class($currentCursor, $nextCursor) implements ProvidesScrollMetadata
        {
            public function __construct(
                private ?string $currentCursor,
                private ?string $nextCursor,
            ) {}

            public function getPageName(): string
            {
                return 'cursor';
            }

            public function getPreviousPage(): ?string
            {
                return null;
            }

            public function getNextPage(): ?string
            {
                return $this->nextCursor;
            }

            public function getCurrentPage(): ?string
            {
                return $this->currentCursor;
            }
        };
    }
}
