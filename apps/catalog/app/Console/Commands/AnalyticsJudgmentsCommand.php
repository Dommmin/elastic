<?php

namespace App\Console\Commands;

use App\Analytics\Simulation\Attractiveness;
use App\Analytics\Simulation\QueryPool;
use App\Services\ProductSearchService;
use App\Services\Search\SearchCriteria;
use Elastic\Elasticsearch\Client;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * `php artisan analytics:judgments` — zestaw ocen `popularity-queries.yaml`
 * z prawdy symulatora (spec ETAP 12, §8 krok 1).
 *
 * Frazy: tekstowe frazy puli symulatora (`QueryPool`), których top 50 ma
 * ≥ 20 produktów z identycznym `_score` — tam kolejność rozstrzyga dziś
 * Lucene, a popularność ma co poprawiać. Wybieramy N fraz z największą
 * grupą remisu (potem największym zbiorem ocenionym, potem alfabetycznie).
 *
 * Oceny: każdy produkt o `_score` ≥ wyniku 50. trafienia (czyli top 50 plus
 * CAŁA grupa remisu, która wystaje poza 50) dostaje `min(3, floor(a·4))`,
 * gdzie `a` = `Attractiveness::for(id, seed)`. Pełna grupa, bo `search:eval
 * --tie-shuffles` losuje kolejność w remisie: z samym top 50 tasowanie
 * wynosiłoby do top-k produkty bez oceny. Grupy większe niż `--max-group`
 * odpadają — nie da się ich ocenić w całości w rozsądnym pliku.
 *
 * Ranking liczony BEZ popularności (boost 0) i ze stałą `preference`: kopie
 * shardu mogą mieć minimalnie różne statystyki BM25, a próg `min_score` musi
 * trafić w ten sam `_score`, co pierwsze zapytanie.
 *
 * `/tests/relevance` jest montowane do catalog-app tylko do odczytu, więc
 * domyślnie plik ląduje w `storage/app/private/`, a `make popularity-judgments`
 * kopiuje go do `tests/relevance/`.
 */
class AnalyticsJudgmentsCommand extends Command
{
    private const ALIAS = 'products-search';

    private const TOP = 50;

    private const MIN_TIE_GROUP = 20;

    private const MAX_GRADE = 3;

    private const PREFERENCE = 'analytics-judgments';

    protected $signature = 'analytics:judgments
        {--seed=1 : Ziarno atrakcyjności (to samo co w analytics:simulate)}
        {--queries=10 : Ile fraz zapisać}
        {--candidates= : Frazy kandydujące po przecinku zamiast puli symulatora}
        {--max-group=1000 : Największy oceniany zbiór na frazę (top 50 + cała grupa remisu na granicy)}
        {--output= : Plik wynikowy (domyślnie storage/app/private/popularity-queries.yaml)}';

    protected $description = 'Generuje tests/relevance/popularity-queries.yaml: frazy z dużymi remisami _score, oceny z ukrytej atrakcyjności symulatora';

    public function __construct(
        private readonly Client $client,
        private readonly ProductSearchService $searchService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Oceny opisują ranking tekstowy; popularność to właśnie to, co potem mierzymy.
        config(['services.search.popularity_boost' => 0]);

        $seed = (int) $this->option('seed');
        $limit = (int) $this->option('queries');
        $maxGroup = (int) $this->option('max-group');
        $phrases = $this->option('candidates') !== null
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('candidates')))))
            : QueryPool::fromCatalog($seed)->phrases();

        $candidates = [];
        $index = null;

        foreach ($phrases as $phrase) {
            $candidate = $this->probe($phrase, $maxGroup);

            if ($candidate !== null) {
                $candidates[] = $candidate;
                $index ??= $candidate['index'];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => [$b['tie_group'], count($b['ids']), $a['query']] <=> [$a['tie_group'], count($a['ids']), $b['query']]);
        $chosen = array_slice($candidates, 0, $limit);

        if ($chosen === []) {
            $this->error('Żadna fraza nie spełnia warunków (grupa remisu ≥ '.self::MIN_TIE_GROUP.' w top '.self::TOP.", zbiór oceniany ≤ {$maxGroup}).");

            return self::FAILURE;
        }

        $output = (string) ($this->option('output') ?: storage_path('app/private/popularity-queries.yaml'));
        file_put_contents($output, $this->render($chosen, $seed, $limit, $maxGroup, (string) $index));

        $this->table(
            ['Fraza', 'Grupa remisu w top '.self::TOP, 'Ocenionych', 'Oceny 0/1/2/3'],
            array_map(fn (array $c) => [$c['query'], $c['tie_group'], count($c['ids']), implode('/', $this->gradeHistogram($c['ids'], $seed))], $chosen),
        );
        $this->info(sprintf('Zapisano %d fraz (z %d kandydatów spełniających warunki) do %s', count($chosen), count($candidates), $output));

        return self::SUCCESS;
    }

    /**
     * @return array{query: string, tie_group: int, ids: list<int>, index: string}|null
     */
    private function probe(string $phrase, int $maxGroup): ?array
    {
        $query = $this->searchService->buildSearchQuery(
            SearchCriteria::fromRequest(Request::create('/search', 'GET', ['q' => $phrase])),
        );

        $top = $this->search(['query' => $query, 'size' => self::TOP, '_source' => false, 'track_total_hits' => true]);
        $hits = $top['hits']['hits'];

        if ($hits === [] || ($top['hits']['total']['value'] ?? 0) === 0) {
            return null;
        }

        $groups = array_count_values(array_map(static fn (array $h) => (string) $h['_score'], $hits));
        $tieGroup = max($groups);

        if ($tieGroup < self::MIN_TIE_GROUP) {
            return null;
        }

        $graded = $this->search([
            'query' => $query,
            'min_score' => end($hits)['_score'],
            'size' => $maxGroup + 1,
            '_source' => false,
            'track_total_hits' => true,
        ]);

        if ($graded['hits']['total']['value'] > $maxGroup) {
            return null;
        }

        $ids = array_map(static fn (array $h) => (int) $h['_id'], $graded['hits']['hits']);
        sort($ids);

        return ['query' => $phrase, 'tie_group' => $tieGroup, 'ids' => $ids, 'index' => (string) $hits[0]['_index']];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function search(array $body): array
    {
        return $this->client->search([
            'index' => self::ALIAS,
            'preference' => self::PREFERENCE,
            'body' => $body,
        ])->asArray();
    }

    private function grade(int $productId, int $seed): int
    {
        return min(self::MAX_GRADE, (int) floor(Attractiveness::for($productId, $seed) * 4));
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function gradeHistogram(array $ids, int $seed): array
    {
        $histogram = array_fill(0, self::MAX_GRADE + 1, 0);

        foreach ($ids as $id) {
            $histogram[$this->grade($id, $seed)]++;
        }

        return $histogram;
    }

    /**
     * YAML pisany ręcznie (nie `Yaml::dump`), żeby mieć nagłówek i komentarz przy
     * każdej frazie. Fraza jako string JSON — poprawny skalar YAML w cudzysłowie.
     *
     * @param  list<array{query: string, tie_group: int, ids: list<int>, index: string}>  $chosen
     */
    private function render(array $chosen, int $seed, int $limit, int $maxGroup, string $index): string
    {
        $command = "php artisan analytics:judgments --seed={$seed} --queries={$limit}";
        if ($maxGroup !== 1000) {
            $command .= " --max-group={$maxGroup}";
        }
        if ($this->option('candidates') !== null) {
            $command .= ' --candidates='.escapeshellarg((string) $this->option('candidates'));
        }

        $lines = [
            '# WYGENEROWANY — nie edytować ręcznie (make popularity-judgments).',
            "# Komenda: {$command}",
            '# Data: '.now()->toDateString().", indeks: {$index}, ranking bez popularności (boost 0).",
            '#',
            '# Co mierzy: czy pętla popularności odzyskuje ukrytą prawdę symulatora z zaszumionych,',
            '# przesuniętych przez pozycję kliknięć (spec ETAP 12, §8 krok 1). Ocena produktu to',
            "# min(3, floor(a·4)), gdzie a = Attractiveness::for(id, {$seed}) — atrakcyjność, której",
            '# wyszukiwarka nie zna — a NIE ludzka ocena trafności.',
            '#',
            '# Frazy: grupa identycznego _score ≥ '.self::MIN_TIE_GROUP.' w top '.self::TOP.'. Ocenione są wszystkie produkty',
            '# z _score ≥ wyniku '.self::TOP.'. trafienia (top '.self::TOP.' + cała grupa remisu na granicy), więc',
            '# tasowanie remisów (search:eval --tie-shuffles) nie wyniesie do top-k produktu bez oceny.',
            '# Reguły z queries.yaml oceniają całe grupy remisu tak samo i z definicji tej poprawy nie widzą.',
            '',
            'queries:',
        ];

        foreach ($chosen as $candidate) {
            $histogram = $this->gradeHistogram($candidate['ids'], $seed);
            $lines[] = '  - query: '.json_encode($candidate['query'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $lines[] = sprintf(
                '    # grupa remisu w top %d: %d, ocenionych: %d, oceny 0/1/2/3: %s',
                self::TOP,
                $candidate['tie_group'],
                count($candidate['ids']),
                implode('/', $histogram),
            );
            $lines[] = '    relevant:';
            $lines[] = '      - ids:';

            foreach ($candidate['ids'] as $id) {
                $lines[] = "          {$id}: {$this->grade($id, $seed)}";
            }
        }

        return implode("\n", $lines)."\n";
    }
}
