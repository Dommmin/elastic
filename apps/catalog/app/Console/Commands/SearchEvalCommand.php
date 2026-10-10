<?php

namespace App\Console\Commands;

use App\Services\ProductSearchService;
use App\Services\Search\RelevanceJudgments;
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

    /**
     * Górna granica grupy remisu sprawdzanej przez `hasMixedGradeTie()` —
     * na seedzie 1500 największa grupa ma kilkadziesiąt dokumentów.
     */
    private const TIE_CHECK_MAX_HITS = 1_000;

    protected $signature = 'search:eval {--k=10 : Głębokość nDCG@k}';

    protected $description = 'Liczy nDCG@k na zestawie zapytań kontrolnych (_rank_eval) tym samym query template co ProductSearchService';

    public function __construct(
        private readonly Client $client,
        private readonly ProductSearchService $searchService,
        private readonly RelevanceJudgments $judgments,
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

        $unstable = array_map(
            fn (array $request) => $this->hasMixedGradeTie($request, $k),
            $requests,
        );

        $this->renderResults($queries, $response, $unstable, $k);

        return $response['failures'] === [] && ! in_array(true, $unstable, true)
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Strażnik determinizmu (docs/RUNBOOK.md #096): czy w top-k jest grupa
     * remisu `_score`, w której dokumenty mają RÓŻNE oceny? Kolejność
     * remisów rozstrzyga wewnętrzny doc id Lucene (zależny od refreshy
     * i merge'ów), więc taka grupa daje nDCG zmieniające się między
     * świeżymi indeksami na tych samych danych.
     *
     * Wszystkie grupy o `_score` WYŻSZYM niż k-ty wynik mieszczą się
     * w top-k w całości; jedyna grupa, która może wystawać poza k, to ta
     * z wynikiem RÓWNYM k-temu — stąd drugie zapytanie z `min_score`, które
     * zwraca ją całą (także członków spoza top-k, mogących się zamienić
     * miejscami z tymi w środku).
     *
     * @param  array{request: array{query: array<string, mixed>}, ratings: array<int, array{_id: string, rating: int}>}  $request
     */
    private function hasMixedGradeTie(array $request, int $k): bool
    {
        $query = $request['request']['query'];
        $grades = array_column($request['ratings'], 'rating', '_id');

        $top = $this->client->search([
            'index' => self::ALIAS,
            'body' => ['query' => $query, 'size' => $k, '_source' => false],
        ])->asArray()['hits']['hits'];

        if ($top === []) {
            return false;
        }

        $tied = $this->client->search([
            'index' => self::ALIAS,
            'body' => [
                'query' => $query,
                'min_score' => end($top)['_score'],
                'size' => self::TIE_CHECK_MAX_HITS,
                '_source' => false,
            ],
        ])->asArray()['hits']['hits'];

        $gradesByScore = [];

        foreach ($tied as $hit) {
            // Klucz jako string: identyczny float32 z ES serializuje się identycznie.
            $gradesByScore[(string) $hit['_score']][$grades[$hit['_id']] ?? 0] = true;
        }

        foreach ($gradesByScore as $distinctGrades) {
            if (count($distinctGrades) > 1) {
                return true;
            }
        }

        return false;
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
     * `relevant` to REGUŁY (kategoria, marka, nazwa...), nie ręczna lista
     * ID-ków — rozwija je `RelevanceJudgments` na podstawie bazy katalogu.
     * Dzięki temu cała grupa remisu `_score` ma tę samą ocenę i wynik nie
     * zależy od kolejności remisów w Lucene (docs/RUNBOOK.md #096).
     *
     * @param  array{query: string, relevant: array<int, array<string, mixed>>}  $entry
     * @return array<string, mixed>
     */
    private function toRankEvalRequest(array $entry, int $index, int $k, string $physicalIndex): array
    {
        $criteria = SearchCriteria::fromRequest(
            Request::create('/search', 'GET', ['q' => $entry['query'], 'per_page' => $k]),
        );
        $ratings = $this->judgments->resolve($entry['relevant']);

        return [
            'id' => "q{$index}",
            'request' => [
                'query' => $this->searchService->buildSearchQuery($criteria),
                'size' => $k,
            ],
            'ratings' => array_map(
                // array_keys() oddaje numeryczne klucze jako int — `_id` w ES to string.
                static fn (int|string $id, int $grade) => [
                    '_index' => $physicalIndex,
                    '_id' => (string) $id,
                    'rating' => $grade,
                ],
                array_keys($ratings),
                $ratings,
            ),
        ];
    }

    /**
     * @param  array<int, array{query: string, relevant: array<int, array<string, mixed>>}>  $queries
     * @param  array<string, mixed>  $response
     * @param  array<int, bool>  $unstable
     */
    private function renderResults(array $queries, array $response, array $unstable, int $k): void
    {
        $rows = [];

        foreach ($queries as $i => $entry) {
            $detail = $response['details']["q{$i}"] ?? null;

            $rows[] = [
                $entry['query'],
                $detail !== null ? number_format((float) $detail['metric_score'], 3) : 'BŁĄD',
                $detail !== null ? count($detail['unrated_docs'] ?? []) : '-',
                $unstable[$i] ? 'TAK' : '-',
            ];
        }

        $this->table(['Zapytanie', "nDCG@{$k}", 'Nieocenione w top-k', 'Remis z różnymi ocenami'], $rows);
        $this->newLine();
        $this->info(sprintf(
            'Średnie nDCG@%d: %.3f (na %d zapytaniach)',
            $k,
            (float) $response['metric_score'],
            count($queries),
        ));

        if (in_array(true, $unstable, true)) {
            $this->error('Wynik NIEDETERMINISTYCZNY: w top-k jest remis `_score` między dokumentami o różnych ocenach — '
                .'kolejność rozstrzyga Lucene, nie trafność. Popraw reguły w queries.yaml tak, żeby cała grupa remisu miała tę samą ocenę (RUNBOOK #096).');
        }

        if ($response['failures'] !== []) {
            $this->error('Błędy _rank_eval: '.json_encode($response['failures'], JSON_PRETTY_PRINT));
        }
    }
}
