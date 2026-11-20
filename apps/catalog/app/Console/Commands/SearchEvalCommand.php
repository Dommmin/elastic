<?php

namespace App\Console\Commands;

use App\Services\ProductSearchService;
use App\Services\Search\RelevanceJudgments;
use App\Services\Search\SearchCriteria;
use App\Services\Search\TieGroupGuard;
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
     * Kod wyjścia, gdy jedynym problemem jest remis `_score` z różnymi ocenami
     * (wynik niedeterministyczny), a `_rank_eval` przeszedł bez błędów. Osobny
     * od FAILURE (1), żeby `tools/es/reindex.sh` mógł go tolerować na zamrożonym
     * starym indeksie przy wznawianiu migracji, a na prawdziwej awarii nadal stawać.
     */
    public const TIE_UNSTABLE = 2;

    /**
     * Ścieżka ABSOLUTNA w kontenerze, nie wędrówka `../../` od repo — ten
     * sam powód i to samo rozwiązanie co `CreateSearchIndexCommand::
     * MAPPINGS_DIR` po stronie Symfony: `/tests/relevance` istnieje TYLKO
     * dlatego, że compose.yaml montuje je jawnie do `catalog-app`. Domyślna
     * wartość `--queries`.
     */
    private const QUERIES_PATH = '/tests/relevance/queries.yaml';

    /**
     * Klucz sortowania rozstrzygający remisy w przebiegu `--tie-shuffles`:
     * deterministyczny hash (SplitMix64) z `_seq_no`, `created_at` i ziarna
     * przebiegu. `_seq_no` jest wspólny dla kopii primary i replica, więc
     * kolejność nie zależy od tego, którą kopię shardu trafi zapytanie;
     * `created_at` rozdziela dokumenty z różnych shardów o tym samym `_seq_no`.
     */
    private const TIE_BREAK_SCRIPT = <<<'PAINLESS'
        long created = doc['created_at'].size() == 0 ? 0L : doc['created_at'].value.toInstant().toEpochMilli();
        long h = doc['_seq_no'].value * -7046029254386353131L + created + ((long) params.seed) * -4658895280553007687L;
        h ^= h >>> 30; h *= -4658895280553007687L;
        h ^= h >>> 27; h *= -7723592293110705685L;
        h ^= h >>> 31;
        return (double) (h >>> 11);
        PAINLESS;

    protected $signature = 'search:eval
        {--k=10 : Głębokość nDCG@k}
        {--index= : Indeks fizyczny zamiast aliasu (np. products-v2)}
        {--queries='.self::QUERIES_PATH.' : Plik zapytań kontrolnych (ścieżka w kontenerze)}
        {--tie-shuffles=0 : Liczba przebiegów z losową (ziarno = numer przebiegu) kolejnością wewnątrz remisów _score; 0 = jeden przebieg jak dotąd}';

    protected $description = 'Liczy nDCG@k na zestawie zapytań kontrolnych (_rank_eval) tym samym query template co ProductSearchService';

    public function __construct(
        private readonly Client $client,
        private readonly ProductSearchService $searchService,
        private readonly RelevanceJudgments $judgments,
        private readonly TieGroupGuard $tieGuard,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Na 300 tys. produktów reguła kategorii/marki rozwija się do kilkudziesięciu
        // tysięcy `ratings` na zapytanie; domyślne 128 MB CLI nie wystarcza (pomiar ETAPU 10).
        ini_set('memory_limit', '1G');

        $queriesPath = (string) $this->option('queries');

        if (! is_file($queriesPath)) {
            $this->error('Brak pliku zapytań kontrolnych: '.$queriesPath);

            return self::FAILURE;
        }

        $data = Yaml::parseFile($queriesPath);
        $k = (int) $this->option('k');
        $shuffles = max(0, (int) $this->option('tie-shuffles'));
        // Z `--index` oceniamy wskazany indeks fizyczny (np. nowy, jeszcze bez aliasu);
        // bez niego — cel aliasu `products-search`, jak dotąd.
        $target = $this->option('index') ?: self::ALIAS;
        $physicalIndex = $this->option('index') ?: $this->resolveAliasTarget();

        $queries = $data['queries'] ?? [];
        $requests = array_map(
            fn (array $entry, int $i) => $this->toRankEvalRequest($entry, $i, $k, $physicalIndex),
            $queries,
            array_keys($queries),
        );

        if ($shuffles > 0) {
            return $this->evaluateWithTieShuffles($target, $queries, $requests, $k, $shuffles);
        }

        $response = $this->rankEval($target, $requests, $k);

        $unstable = array_map(
            fn (array $request) => $this->tieGuard->hasMixedGradeTie(
                $target,
                $request['request']['query'],
                array_column($request['ratings'], 'rating', '_id'),
                $k,
            ),
            $requests,
        );

        $this->renderResults($queries, $response, $unstable, $k);

        if ($response['failures'] !== []) {
            return self::FAILURE;
        }

        return in_array(true, $unstable, true) ? self::TIE_UNSTABLE : self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $requests
     * @return array<string, mixed>
     */
    private function rankEval(string $target, array $requests, int $k): array
    {
        return $this->client->rankEval([
            'index' => $target,
            'body' => [
                'requests' => $requests,
                'metric' => ['dcg' => ['k' => $k, 'normalize' => true]],
            ],
        ])->asArray();
    }

    /**
     * Stabilna linia bazowa mimo remisów (spec ETAP 12, §8 krok 2): N przebiegów
     * `_rank_eval`, w przebiegu `i` remisy `_score` rozstrzyga seedowany hash
     * (drugi klucz sortowania), a nie wewnętrzny doc id Lucene.
     *
     * Dlaczego sortowanie, a nie `function_score` z `random_score` o wadze 1e-6
     * dodawanym do wyniku: `_score` to float32. Przy wyniku ~27 odstęp między
     * sąsiednimi floatami to 2^-19 ≈ 1.9e-6, więc dodatek < 1e-6 zaokrągla się
     * do zera dla ~95% dokumentów, a reszta dostaje +1 ulp. Zamiast losowej
     * kolejności powstają dwa poziomy, a w każdym dalej rozstrzyga doc id Lucene
     * (sprawdzone na żywym `products-v6`: top 50 nadal z jednym `_score`). Duża
     * grupa daje wtedy losowy podzbiór w kolejności Lucene, mała (< ~200) prawie
     * się nie tasuje. Większa waga zaczęłaby przestawiać dokumenty o RÓŻNYCH
     * wynikach (przy boost popularności wyniki są prawie ciągłe). Sortowanie
     * `[_score desc, hash asc]` zostawia `_score` bit w bit i zmienia wyłącznie
     * kolejność dokumentów o identycznym wyniku — z konstrukcji, bez progu.
     *
     * Strażnik remisów (`TieGroupGuard`) jest tu zbędny: remisy z różnymi ocenami
     * są właśnie tym, co uśredniamy, a rozrzut widać w min–max.
     *
     * @param  array<int, array{query: string, relevant: array<int, array<string, mixed>>}>  $queries
     * @param  array<int, array<string, mixed>>  $requests
     */
    private function evaluateWithTieShuffles(string $target, array $queries, array $requests, int $k, int $shuffles): int
    {
        /** @var array<int, list<float>> $perQuery */
        $perQuery = [];
        /** @var array<int, int> $maxUnrated */
        $maxUnrated = [];
        /** @var list<float> $overall */
        $overall = [];

        for ($seed = 1; $seed <= $shuffles; $seed++) {
            $shuffled = array_map(static function (array $request) use ($seed): array {
                $request['request']['sort'] = [
                    ['_score' => 'desc'],
                    ['_script' => [
                        'type' => 'number',
                        'order' => 'asc',
                        'script' => ['source' => self::TIE_BREAK_SCRIPT, 'params' => ['seed' => $seed]],
                    ]],
                ];

                return $request;
            }, $requests);

            $response = $this->rankEval($target, $shuffled, $k);

            if ($response['failures'] !== []) {
                $this->error("Błędy _rank_eval (przebieg {$seed}): ".json_encode($response['failures'], JSON_PRETTY_PRINT));

                return self::FAILURE;
            }

            $overall[] = (float) $response['metric_score'];

            foreach (array_keys($queries) as $i) {
                $detail = $response['details']["q{$i}"];
                $perQuery[$i][] = (float) $detail['metric_score'];
                $maxUnrated[$i] = max($maxUnrated[$i] ?? 0, count($detail['unrated_docs'] ?? []));
            }
        }

        $rows = [];
        foreach ($queries as $i => $entry) {
            $scores = $perQuery[$i];
            $rows[] = [
                $entry['query'],
                number_format(array_sum($scores) / count($scores), 3),
                number_format(min($scores), 3),
                number_format(max($scores), 3),
                $maxUnrated[$i],
            ];
        }

        $mean = array_sum($overall) / count($overall);

        $this->table(['Zapytanie', "nDCG@{$k} średnio", 'min', 'max', 'Nieocenione w top-k (maks.)'], $rows);
        $this->newLine();
        $this->info(sprintf(
            'Średnie nDCG@%d z %d przebiegów: %.3f (min %.3f, max %.3f) na %d zapytaniach',
            $k,
            $shuffles,
            $mean,
            min($overall),
            max($overall),
            count($queries),
        ));
        // Linie maszynowe; min/max to skrajne średnie pojedynczych przebiegów.
        $this->line(sprintf('NDCG=%s', $mean));
        $this->line(sprintf('NDCG_MIN=%s', min($overall)));
        $this->line(sprintf('NDCG_MAX=%s', max($overall)));

        return self::SUCCESS;
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
        // Linia maszynowa dla skryptów (bramka reindeksu); kropka dziesiętna niezależna od locale.
        $this->line(sprintf('NDCG=%s', (float) $response['metric_score']));

        if (in_array(true, $unstable, true)) {
            $this->error('Wynik NIEDETERMINISTYCZNY: w top-k jest remis `_score` między dokumentami o różnych ocenach — '
                .'kolejność rozstrzyga Lucene, nie trafność. Popraw reguły w queries.yaml tak, żeby cała grupa remisu miała tę samą ocenę (RUNBOOK #096).');
        }

        if ($response['failures'] !== []) {
            $this->error('Błędy _rank_eval: '.json_encode($response['failures'], JSON_PRETTY_PRINT));
        }
    }
}
