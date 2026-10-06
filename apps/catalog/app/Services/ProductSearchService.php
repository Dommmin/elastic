<?php

namespace App\Services;

use App\Services\Search\InvalidSearchCursorException;
use App\Services\Search\SearchCriteria;
use App\Services\Search\SearchResult;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;

/**
 * D-09: cała logika wyszukiwania żyje TUTAJ, nie w kontrolerze ani w
 * komponencie Vue — `SearchController` (Inertia), `Api\SearchController`
 * (JSON) i `search:eval` (ETAP 7, Faza 5) wołają dokładnie te same metody.
 *
 * Zasada z docs/02-APLIKACJE.md: ten serwis zna TYLKO alias `products-search`
 * i kontrakt zapytań (pola z mapowania jako "API", nie jako implementacja).
 * Fizyczną nazwę indeksu (`products-v1`) i reindeks zna wyłącznie
 * search-service (Symfony) — stąd nigdzie tutaj nie ma `products-v1`.
 */
class ProductSearchService
{
    private const ALIAS = 'products-search';

    /**
     * Jawny, mały limit (nie `track_total_hits: true`) — uczy kosztu
     * liczenia dokładnego `total` powyżej pierwszych N trafień
     * (docs/03-SCIEZKA-NAUKI.md, moduł 9). Powyżej tej granicy total.value
     * przestaje rosnąć i `isLowerBound` w odpowiedzi ES staje się `true`.
     */
    private const TRACK_TOTAL_HITS_LIMIT = 10_000;

    /**
     * Progi widełek cenowych (grosze) do agregacji `price` w facetach —
     * stałe, bo to demo dla nauki, nie produkcyjny dynamiczny binning
     * (który wymagałby `percentiles`, moduł 8).
     */
    private const PRICE_RANGES = [
        ['to' => 10_000],
        ['from' => 10_000, 'to' => 50_000],
        ['from' => 50_000, 'to' => 200_000],
        ['from' => 200_000, 'to' => 500_000],
        ['from' => 500_000],
    ];

    /**
     * `search_after` STABILNEJ paginacji potrzebuje unikalnego tie-breakera.
     * `_id` wygląda kusząco, ale ES odrzuca sort po `_id` bez włączonego
     * (i odradzanego) fielddata na polu `_id` — sprawdzone na żywym
     * klastrze przy pierwszej próbie (`illegal_argument_exception:
     * Fielddata access on the _id field is disallowed`). Poprawny sposób
     * (docs/03-SCIEZKA-NAUKI.md, moduł 9) to Point In Time + `_shard_doc`:
     * PIT zamraża widok shardów na czas sesji stronicowania, `_shard_doc`
     * to wewnętrzny, zawsze-unikalny numer dokumentu na shardzie.
     *
     * Każde zapytanie z PIT PRZEDŁUŻA jego życie o keep_alive. Krótko
     * (a nie np. 30m), bo otwarty PIT blokuje sprzątanie starych segmentów
     * po merge'ach — tysiąc porzuconych kart przeglądarki = tysiąc trzymanych
     * segmentów. Wygaśnięcie obsługuje `searchWithPit()` (RUNBOOK #023).
     */
    private const PIT_KEEP_ALIVE = '1m';

    public function __construct(
        private readonly Client $client,
    ) {}

    public function search(SearchCriteria $criteria): SearchResult
    {
        $filters = $this->buildFilters($criteria);
        $filters[] = $this->cheapestOfferClause();

        [$pitId, $searchAfter] = $this->resolvePagination($criteria);

        $body = [
            'query' => [
                'bool' => [
                    'must' => [$this->buildTextQuery($criteria->q)],
                    'filter' => $filters,
                ],
            ],
            'sort' => $this->buildSort($criteria),
            'size' => $criteria->perPage,
            'pit' => ['id' => $pitId, 'keep_alive' => self::PIT_KEEP_ALIVE],
            // Górna granica kosztu liczenia total — patrz komentarz przy stałej.
            'track_total_hits' => self::TRACK_TOTAL_HITS_LIMIT,
        ];

        if ($searchAfter === null) {
            // Facety liczone w TYM SAMYM zapytaniu co wyniki (taniej niż
            // osobny request) — ale tylko dla PIERWSZEJ strony. Kolejne
            // strony (infinite scroll) mają identyczne facety, bo zapytanie
            // i filtry się nie zmieniły — liczenie ich od nowa przy każdym
            // przewinięciu to czysty koszt agregacji bez żadnej informacji.
            $body['aggs'] = $this->buildFacetAggs($criteria);
        } else {
            $body['search_after'] = $searchAfter;
        }

        $response = $this->searchWithPit($body, isContinuation: $searchAfter !== null);

        return $this->mapResponse($response, $criteria->perPage);
    }

    /**
     * Z `pit` w body NIE podaje się `index` — PIT już wskazuje, na jakim
     * (zamrożonym) widoku shardów szukać.
     *
     * Wygasły PIT (użytkownik czytał wyniki dłużej niż PIT_KEEP_ALIVE, zanim
     * przewinął dalej) to `404 search_context_missing_exception` — sprawdzone
     * na żywym klastrze. Zamiast 500 otwieramy NOWY PIT i kontynuujemy od tych
     * samych wartości `search_after`. Kompromis, świadomie: `_shard_doc`
     * z nowego PIT-a odpowiada staremu tylko, jeśli między nimi nie było
     * zapisów/merge'ów na shardzie — inaczej na granicy strony może zdarzyć
     * się duplikat albo pominięcie. Duplikat i tak zdejmuje `matchOn('id')`
     * po stronie Inertii; pominięcie jednej pozycji przy wznowieniu po
     * minucie bezczynności jest akceptowalne (RUNBOOK #023).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function searchWithPit(array $body, bool $isContinuation): array
    {
        try {
            return $this->client->search(['body' => $body])->asArray();
        } catch (ClientResponseException $e) {
            if (! $isContinuation || ! $this->isMissingSearchContext($e)) {
                throw $e;
            }

            $body['pit']['id'] = $this->openPit();

            return $this->client->search(['body' => $body])->asArray();
        }
    }

    private function isMissingSearchContext(ClientResponseException $e): bool
    {
        return $e->getCode() === 404
            && str_contains($e->getMessage(), 'search_context_missing_exception');
    }

    private function openPit(): string
    {
        return $this->client->openPointInTime([
            'index' => self::ALIAS,
            'keep_alive' => self::PIT_KEEP_ALIVE,
        ])->asArray()['id'];
    }

    /**
     * @return array{0: string, 1: ?array<int, mixed>}
     */
    private function resolvePagination(SearchCriteria $criteria): array
    {
        if ($criteria->cursor !== null) {
            $decoded = $this->decodeCursor($criteria->cursor);

            return [$decoded['pit'], $decoded['sort']];
        }

        return [$this->openPit(), null];
    }

    /**
     * Cięższa agregacja (histogram po cenie ze WSZYSTKICH ofert), celowo
     * NIE liczona w `search()` — kontroler wywołuje ją leniwie przez
     * `Inertia::defer()` (ETAP 7, Faza 4), żeby nie blokować pierwszego
     * renderu wyników.
     *
     * @return array<int, array{price: int, count: int}>
     */
    public function priceHistogram(SearchCriteria $criteria): array
    {
        $body = [
            'size' => 0,
            'query' => [
                'bool' => [
                    'must' => [$this->buildTextQuery($criteria->q)],
                    // Histogram cenowy ignoruje WŁASNY filtr ceny — inaczej
                    // zawężenie suwakiem obcinałoby histogram do samego
                    // siebie, co jest bezużyteczne (ten sam problem co
                    // facety, patrz buildFacetAggs()).
                    'filter' => $this->buildFilters($criteria, exclude: ['price']),
                ],
            ],
            'aggs' => [
                'price_histogram' => [
                    'histogram' => [
                        'field' => 'price_min',
                        'interval' => 5_000,
                        'min_doc_count' => 0,
                    ],
                ],
            ],
        ];

        $response = $this->client->search([
            'index' => self::ALIAS,
            'body' => $body,
        ])->asArray();

        return array_map(
            static fn (array $bucket): array => ['price' => (int) $bucket['key'], 'count' => (int) $bucket['doc_count']],
            $response['aggregations']['price_histogram']['buckets'] ?? [],
        );
    }

    /**
     * Autocomplete — pole `name.ac` (edge_ngram, mapowanie products-v1),
     * `match_bool_prefix` żeby działało już przy niedokończonym ostatnim
     * słowie. Bez agregacji, bez `_source` niepotrzebnych pól — ma być
     * najtańsze z możliwych zapytań (leci przy każdym wciśniętym znaku).
     *
     * @return array<int, array{id: string, name: string, brand: ?string}>
     */
    public function suggest(string $q, int $limit = 8): array
    {
        $q = trim($q);

        if ($q === '') {
            return [];
        }

        $response = $this->client->search([
            'index' => self::ALIAS,
            'body' => [
                'size' => $limit,
                '_source' => ['name', 'brand'],
                'query' => [
                    'match_bool_prefix' => [
                        'name.ac' => $q,
                    ],
                ],
            ],
        ])->asArray();

        return array_map(
            static fn (array $hit): array => [
                'id' => (string) $hit['_id'],
                'name' => $hit['_source']['name'],
                'brand' => $hit['_source']['brand'] ?? null,
            ],
            $response['hits']['hits'] ?? [],
        );
    }

    // ------------------------------------------------------------------
    // Budowanie DSL — publiczne i bezstanowe celowo: `search:eval`
    // (Faza 5) używa `buildSearchQuery()` wprost, a testy jednostkowe
    // (tests/Unit/Services/ProductSearchServiceQueryTest.php) sprawdzają
    // te metody BEZ wywoływania ES.
    // ------------------------------------------------------------------

    /**
     * Sama `query` (bez sortu/agregacji/stronicowania) — dokładnie to,
     * czego potrzebuje `_rank_eval` (jeden template dla wyników i dla
     * oceny trafności, żeby nie ocenić czegoś innego, niż faktycznie
     * widzi użytkownik).
     *
     * @return array<string, mixed>
     */
    public function buildSearchQuery(SearchCriteria $criteria): array
    {
        return [
            'bool' => [
                'must' => [$this->buildTextQuery($criteria->q)],
                'filter' => $this->buildFilters($criteria),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTextQuery(string $q): array
    {
        if ($q === '') {
            return ['match_all' => new \stdClass];
        }

        return [
            'multi_match' => [
                'query' => $q,
                'type' => 'best_fields',
                'tie_breaker' => 0.3,
                'fields' => ['name^3', 'name.ac', 'brand^2', 'description'],
            ],
        ];
    }

    /**
     * Filtry — WYŁĄCZNIE `bool.filter`, nigdy `must` (moduł 6: filtr nie
     * wpływa na `_score` i podlega cache'owaniu). `$exclude` pozwala
     * pominąć jeden wymiar — używane przez facety, żeby liczyć np.
     * brandy z filtrami WSZYSTKICH innych wymiarów oprócz brandu.
     *
     * @param  array<int, string>  $exclude
     * @return array<int, array<string, mixed>>
     */
    public function buildFilters(SearchCriteria $criteria, array $exclude = []): array
    {
        $filters = [];

        if (! in_array('brand', $exclude, true) && $criteria->brands !== []) {
            $filters[] = ['terms' => ['brand' => $criteria->brands]];
        }

        if (! in_array('category', $exclude, true) && $criteria->categoryPath !== null) {
            $filters[] = ['term' => ['category.path' => $criteria->categoryPath]];
        }

        if (! in_array('price', $exclude, true)) {
            // Zakres nakłada się na [price_min, price_max] PRODUKTU (czyli
            // "ma choć jedną ofertę w tym przedziale"), nie wymaga zapytania
            // nested po `offers.price` — dokładnie po to `price_min`/
            // `price_max` istnieją jako osobne pola top-level w mapowaniu.
            if ($criteria->priceMin !== null) {
                $filters[] = ['range' => ['price_max' => ['gte' => $criteria->priceMin]]];
            }
            if ($criteria->priceMax !== null) {
                $filters[] = ['range' => ['price_min' => ['lte' => $criteria->priceMax]]];
            }
        }

        if (! in_array('in_stock', $exclude, true) && $criteria->inStock !== null) {
            $filters[] = ['term' => ['in_stock' => $criteria->inStock]];
        }

        return $filters;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function buildSort(SearchCriteria $criteria): array
    {
        // `_shard_doc` jako dogrywka na końcu KAŻDEGO sortu — bez stabilnego
        // tie-breakera `search_after` może pomijać albo dublować wyniki
        // o identycznej wartości głównego pola sortowania (moduł 9). Wymaga
        // PIT (patrz PIT_KEEP_ALIVE) — zwykłe `_id` ES odrzuca (fielddata
        // wyłączone, patrz docblock stałej).
        return match ($criteria->sort) {
            'price_asc' => [['price_min' => 'asc'], ['_shard_doc' => 'asc']],
            'price_desc' => [['price_min' => 'desc'], ['_shard_doc' => 'asc']],
            'newest' => [['created_at' => 'desc'], ['_shard_doc' => 'asc']],
            default => [['_score' => 'desc'], ['_shard_doc' => 'asc']],
        };
    }

    /**
     * Facety jako "filtered aggregations" WEWNĄTRZ `global` agregacji.
     *
     * Dwie różne pułapki, obie z modułu 8 (docs/03-SCIEZKA-NAUKI.md), i obie
     * naprawdę złapane przy pierwszym uruchomieniu na żywym ES, nie tylko
     * przeczytane:
     *
     * 1. Agregacje BEZ `global` liczą się na wyniku GŁÓWNEGO zapytania —
     *    włącznie z jego `bool.filter`. Filtrowanie po brand=Nike w
     *    głównym query automatycznie ścinało agregację brand do jednego
     *    bucketu (Nike), niezależnie od tego, co robił `exclude` w
     *    `buildFilters()` — bo `filter` sub-agg i tak działał WEWNĄTRZ
     *    już zawężonego zbioru. `global` resetuje kontekst do CAŁEGO
     *    indeksu, dopiero wewnątrz niego własny `filter` nakłada
     *    dokładnie te warunki, które mają obowiązywać.
     * 2. `global` pomija też tekstowe zapytanie (`q`) — a chcemy, żeby
     *    facety nadal odzwierciedlały wpisaną frazę ("buty do biegania"
     *    powinno zawęzić listę marek do tych, które faktycznie sprzedają
     *    buty do biegania), tylko NIE checkboxy innych wymiarów. Stąd
     *    `multi_match` z `buildTextQuery()` jest ręcznie doklejany do
     *    filtra KAŻDEGO wymiaru — w kontekście `filter` nie liczy się
     *    do `_score`, więc jego obecność tu jest bezpieczna.
     *
     * @return array<string, mixed>
     */
    private function buildFacetAggs(SearchCriteria $criteria): array
    {
        $dimensionAggs = [
            'brand' => ['terms' => ['field' => 'brand', 'size' => 20]],
            'category' => ['terms' => ['field' => 'category.path', 'size' => 30]],
            'price' => ['range' => ['field' => 'price_min', 'ranges' => self::PRICE_RANGES]],
            'in_stock' => ['terms' => ['field' => 'in_stock', 'size' => 2]],
        ];

        $perDimension = [];

        foreach ($dimensionAggs as $dimension => $agg) {
            $filterClauses = $this->buildFilters($criteria, exclude: [$dimension]);

            if ($criteria->q !== '') {
                $filterClauses[] = $this->buildTextQuery($criteria->q);
            }

            $perDimension[$dimension] = [
                'filter' => $filterClauses === []
                    ? ['match_all' => new \stdClass]
                    : ['bool' => ['filter' => $filterClauses]],
                'aggs' => [$dimension => $agg],
            ];
        }

        return [
            'facets' => [
                'global' => new \stdClass,
                'aggs' => $perDimension,
            ],
        ];
    }

    /**
     * Zawsze dopięty (nie da się wyłączyć filtrem) `nested` na `offers`
     * z `match_all` w środku — w kontekście filtra nie wpływa na `_score`
     * i zawsze przechodzi (każdy produkt ma >=1 ofertę), więc jedyny jego
     * cel to `inner_hits`: najtańsza oferta per produkt.
     *
     * Mapowanie `products-v1` ma JEDEN dokument na produkt z zagnieżdżonymi
     * ofertami (nie doc-per-oferta), więc klasyczny `collapse` (moduł 6)
     * nie ma tu zastosowania — `nested inner_hits` daje ten sam efekt
     * biznesowy ("pokaż najtańszą ofertę"). Patrz docs/RUNBOOK.md #017
     * (dopisek ETAP 7) po kontekst decyzji.
     *
     * @return array<string, mixed>
     */
    private function cheapestOfferClause(): array
    {
        return [
            'nested' => [
                'path' => 'offers',
                'query' => ['match_all' => new \stdClass],
                'inner_hits' => [
                    'name' => 'cheapest_offer',
                    'size' => 1,
                    'sort' => [['offers.price' => 'asc']],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function mapResponse(array $response, int $perPage): SearchResult
    {
        $hits = $response['hits']['hits'] ?? [];

        $items = array_map(fn (array $hit) => $this->mapHit($hit), $hits);

        $lastHit = end($hits);
        $nextCursor = ($lastHit !== false && count($hits) >= $perPage && isset($lastHit['sort']) && isset($response['pit_id']))
            ? $this->encodeCursor($response['pit_id'], $lastHit['sort'])
            : null;

        return new SearchResult(
            items: $items,
            facets: $this->mapFacets($response['aggregations'] ?? []),
            total: [
                'value' => (int) ($response['hits']['total']['value'] ?? 0),
                'isLowerBound' => ($response['hits']['total']['relation'] ?? 'eq') === 'gte',
            ],
            nextCursor: $nextCursor,
            tookMs: (int) ($response['took'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $hit
     * @return array<string, mixed>
     */
    private function mapHit(array $hit): array
    {
        $source = $hit['_source'];
        $cheapestOffer = $hit['inner_hits']['cheapest_offer']['hits']['hits'][0]['_source'] ?? null;

        return [
            'id' => $hit['_id'],
            'score' => $hit['_score'],
            'name' => $source['name'],
            'brand' => $source['brand'] ?? null,
            'category' => $source['category'] ?? null,
            'price_min' => $source['price_min'] ?? null,
            'price_max' => $source['price_max'] ?? null,
            'in_stock' => $source['in_stock'] ?? false,
            'rating_avg' => $source['rating_avg'] ?? null,
            'rating_count' => $source['rating_count'] ?? 0,
            'cheapest_offer' => $cheapestOffer,
        ];
    }

    /**
     * @param  array<string, mixed>  $aggregations
     * @return array<string, mixed>
     */
    private function mapFacets(array $aggregations): array
    {
        $facets = [];
        $perDimension = $aggregations['facets'] ?? [];

        foreach (['brand', 'category', 'price', 'in_stock'] as $dimension) {
            $buckets = $perDimension[$dimension][$dimension]['buckets'] ?? [];

            $facets[$dimension] = array_map(
                static fn (array $bucket): array => [
                    'key' => $bucket['key_as_string'] ?? $bucket['key'],
                    'from' => $bucket['from'] ?? null,
                    'to' => $bucket['to'] ?? null,
                    'count' => (int) $bucket['doc_count'],
                ],
                $buckets,
            );
        }

        return $facets;
    }

    /**
     * Cursor niesie PIT ID razem z wartościami sortu — kolejna strona MUSI
     * użyć tego samego (zamrożonego) PIT-a, inaczej `_shard_doc` z pierwszej
     * strony przestałby być spójny z drugą, gdyby w międzyczasie doszło
     * do reindeksu/merge'a shardów.
     *
     * @param  array<int, mixed>  $sortValues
     */
    private function encodeCursor(string $pitId, array $sortValues): string
    {
        return base64_encode(json_encode(['pit' => $pitId, 'sort' => $sortValues], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{pit: string, sort: array<int, mixed>}
     */
    private function decodeCursor(string $cursor): array
    {
        // Kursor przychodzi z query stringa — czyli od użytkownika. Ucięty
        // przy kopiowaniu linku albo ręcznie zmieniony nie może kończyć się
        // 500 (JsonException), tylko jasnym 400.
        $decoded = json_decode((string) base64_decode($cursor, strict: true), associative: true);

        if (! is_array($decoded) || ! is_string($decoded['pit'] ?? null) || ! is_array($decoded['sort'] ?? null)) {
            throw new InvalidSearchCursorException;
        }

        return $decoded;
    }
}
