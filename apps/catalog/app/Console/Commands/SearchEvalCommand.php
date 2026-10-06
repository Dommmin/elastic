<?php

namespace App\Console\Commands;

use App\Services\ProductSearchService;
use App\Services\Search\SearchCriteria;
use Elastic\Elasticsearch\Client;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * `php artisan search:eval` — nDCG@k na zestawie zapytań kontrolnych
 * (`/tests/relevance/queries.yaml`, montowane osobno do kontenera catalog-app
 * — patrz komentarz przy `catalog-app.volumes` w compose.yaml, ten sam
 * powód co przy `search:index:create` w Symfony) przez ES `_rank_eval`
 * (docs/03-SCIEZKA-NAUKI.md, moduł 7; docs/06-PLAN-WDROZENIA.md, ETAP 7).
 *
 * KLUCZOWE: query template do oceny bierzemy z `ProductSearchService::
 * buildSearchQuery()` — DOKŁADNIE tego samego, którego `search()` używa dla
 * prawdziwych wyników. Osobny, "podobny" template do ewaluacji byłby
 * bezużyteczny — mierzyłby trafność zapytania, którego użytkownik nigdy
 * nie zobaczy.
 */
class SearchEvalCommand extends Command
{
    private const ALIAS = 'products-search';

    /**
     * Ścieżka ABSOLUTNA w kontenerze, nie wędrówka `../../` od repo — ten
     * sam powód i to samo rozwiązanie co `CreateSearchIndexCommand::
     * MAPPINGS_DIR` po stronie Symfony: `/tests/relevance` istnieje TYLKO
     * dlatego, że compose.yaml montuje je jawnie do `catalog-app`.
     */
    private const QUERIES_PATH = '/tests/relevance/queries.yaml';

    protected $signature = 'search:eval {--k=10 : Głębokość nDCG@k}';

    protected $description = 'Liczy nDCG@k na zestawie zapytań kontrolnych (_rank_eval) tym samym query template co ProductSearchService';

    public function __construct(
        private readonly Client $client,
        private readonly ProductSearchService $searchService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! is_file(self::QUERIES_PATH)) {
            $this->error('Brak pliku zapytań kontrolnych: '.self::QUERIES_PATH);

            return self::FAILURE;
        }

        $data = Yaml::parseFile(self::QUERIES_PATH);
        $k = (int) $this->option('k');
        $physicalIndex = $this->resolveAliasTarget();

        $queries = $data['queries'] ?? [];
        $requests = array_map(
            fn (array $entry, int $i) => $this->toRankEvalRequest($entry, $i, $k, $physicalIndex),
            $queries,
            array_keys($queries),
        );

        $response = $this->client->rankEval([
            'index' => self::ALIAS,
            'body' => [
                'requests' => $requests,
                'metric' => ['dcg' => ['k' => $k, 'normalize' => true]],
            ],
        ])->asArray();

        $this->renderResults($queries, $response, $k);

        return $response['failures'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * `_rank_eval` dopasowuje `ratings` do trafień po `_index`+`_id`
     * DOKŁADNIE — a `_source`/`hits` zwraca ZAWSZE fizyczny indeks
     * (`products-v1`), nigdy alias, nawet gdy zapytanie poszło przez alias.
     * Ocena po `_index: products-search` (alias) daje same "unrated_docs" —
     * złapane właśnie tak, na żywym klastrze, nie w dokumentacji.
     *
     * Rozwiązujemy alias TUTAJ, w komendzie diagnostycznej, świadomie NIE
     * w `ProductSearchService` — serwis produkcyjny ma zostać ślepy na
     * fizyczne nazwy indeksów (D-11/D-09, docs/02-APLIKACJE.md), `search:eval`
     * jest narzędziem deweloperskim i może znać ten szczegół.
     */
    private function resolveAliasTarget(): string
    {
        $aliases = $this->client->indices()->getAlias(['name' => self::ALIAS])->asArray();

        return array_key_first($aliases);
    }

    /**
     * @param  array{query: string, relevant: array<int, array{id: string|int, grade: int}>}  $entry
     * @return array<string, mixed>
     */
    private function toRankEvalRequest(array $entry, int $index, int $k, string $physicalIndex): array
    {
        $criteria = SearchCriteria::fromRequest(
            Request::create('/search', 'GET', ['q' => $entry['query'], 'per_page' => $k]),
        );

        return [
            'id' => "q{$index}",
            'request' => [
                'query' => $this->searchService->buildSearchQuery($criteria),
                'size' => $k,
            ],
            'ratings' => array_map(
                static fn (array $r) => [
                    '_index' => $physicalIndex,
                    '_id' => (string) $r['id'],
                    'rating' => (int) $r['grade'],
                ],
                $entry['relevant'],
            ),
        ];
    }

    /**
     * @param  array<int, array{query: string, relevant: array<int, array{id: string|int, grade: int}>}>  $queries
     * @param  array<string, mixed>  $response
     */
    private function renderResults(array $queries, array $response, int $k): void
    {
        $rows = [];

        foreach ($queries as $i => $entry) {
            $detail = $response['details']["q{$i}"] ?? null;

            $rows[] = [
                $entry['query'],
                $detail !== null ? number_format((float) $detail['metric_score'], 3) : 'BŁĄD',
                $detail !== null ? count($detail['unrated_docs'] ?? []) : '-',
            ];
        }

        $this->table(['Zapytanie', "nDCG@{$k}", 'Nieocenione w top-k'], $rows);
        $this->newLine();
        $this->info(sprintf(
            'Średnie nDCG@%d: %.3f (na %d zapytaniach)',
            $k,
            (float) $response['metric_score'],
            count($queries),
        ));

        if ($response['failures'] !== []) {
            $this->error('Błędy _rank_eval: '.json_encode($response['failures'], JSON_PRETTY_PRINT));
        }
    }
}
