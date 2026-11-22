<?php

namespace App\Console\Commands;

use App\Services\Search\Lab\LabEvalRunner;
use App\Services\Search\Lab\LabJudgments;
use App\Services\Search\Lab\LabQueryBuilder;
use App\Services\Search\Lab\LabVariant;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * `php artisan search:eval:lab` (`make lab-eval`) — nDCG@k wariantów wyszukiwania
 * (bm25, knn, hybrid-bool, rrf-client, rrf-native) na węźle es-lab, w podziale na grupy
 * zapytań z `/tests/relevance/semantic-queries.yaml` (ETAP 14, spec §6).
 *
 * nDCG liczy PHP (`Ndcg`) dla wszystkich wariantów; `--check-rank-eval` porównuje go
 * z `_rank_eval` tam, gdzie wariant jest zwykłym `query`, a `--check-rrf` sprawdza,
 * że RRF w PHP i retriever `rrf` w ES dają ten sam top-k (z dokładnością do remisów).
 */
class SearchEvalLabCommand extends Command
{
    /** Jak w `search:eval`: remis `_score` z różnymi ocenami w top-k bm25. */
    public const TIE_UNSTABLE = 2;

    private const QUERIES_PATH = '/tests/relevance/semantic-queries.yaml';

    protected $signature = 'search:eval:lab
        {--variant=* : Warianty (domyślnie bm25, knn, hybrid-bool, rrf-client, rrf-native)}
        {--group=* : Grupy zapytań (lexical, descriptive, exact; domyślnie wszystkie)}
        {--k=10 : Głębokość nDCG@k}
        {--queries='.self::QUERIES_PATH.' : Plik zapytań laboratoryjnych (ścieżka w kontenerze)}
        {--json= : Zapisz wynik jako JSON na dysku local (storage/app/private), np. lab/eval-14a.json}
        {--check-rank-eval : Porównaj nDCG z PHP z _rank_eval (warianty wyrażalne jako query)}
        {--check-rrf : Porównaj top-k rrf-client z rrf-native}
        {--latency=0 : Liczba przebiegów po wszystkich zapytaniach do pomiaru p50/p99 (0 = bez pomiaru)}
        {--recall : recall@k HNSW (knn na vec_float) względem knn-exact (brute force na vec_float)}';

    protected $description = 'Porównuje warianty wyszukiwania (BM25, kNN, hybryda, RRF) na węźle lab: nDCG@k wariant × grupa';

    public function __construct(
        private readonly LabEvalRunner $runner,
        private readonly LabJudgments $judgments,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Reguły kategorii rozwijają się do tysięcy ocen na zapytanie (jak w search:eval).
        ini_set('memory_limit', '1G');

        $variants = $this->variants();
        if ($variants === null) {
            return self::FAILURE;
        }

        $groups = $this->option('group') ?: LabJudgments::GROUPS;
        $unknownGroups = array_diff($groups, LabJudgments::GROUPS);
        if ($unknownGroups !== []) {
            $this->error('Nieznana grupa: '.implode(', ', $unknownGroups).' (dozwolone: '.implode(', ', LabJudgments::GROUPS).').');

            return self::FAILURE;
        }

        $path = (string) $this->option('queries');
        if (! is_file($path)) {
            $this->error('Brak pliku zapytań laboratoryjnych: '.$path);

            return self::FAILURE;
        }

        $k = (int) $this->option('k');
        $queries = array_values(array_filter(
            $this->judgments->load($path),
            static fn (array $entry): bool => in_array($entry['group'], $groups, true),
        ));

        try {
            return $this->evaluate($variants, $groups, $queries, $k);
        } catch (NoNodeAvailableException) {
            $this->error('Węzeł lab (es-lab) nie odpowiada — uruchom go: make lab-mode-on && make lab-up.');

            return self::FAILURE;
        } catch (ClientResponseException $e) {
            $body = (string) $e->getResponse()->getBody();

            if ($e->getCode() === 403 && str_contains($body, 'license')) {
                $trial = array_map(static fn (LabVariant $v): string => $v->value, array_filter($variants, static fn (LabVariant $v): bool => $v->requiresTrial()));
                $this->error('Licencja węzła lab nie obejmuje wariantów '.implode(', ', $trial).' — włącz trial: make lab-setup.');

                return self::FAILURE;
            }

            $this->error('ES odrzucił zapytanie ('.$e->getCode().'): '.self::reason($body));

            return self::FAILURE;
        } catch (ServerResponseException $e) {
            $this->error('Błąd węzła lab ('.$e->getCode().'): '.self::reason((string) $e->getResponse()->getBody()));

            return self::FAILURE;
        }
    }

    /**
     * @param  list<LabVariant>  $variants
     * @param  list<string>  $groups
     * @param  list<array{query: string, group: string, ratings: array<string, int>}>  $queries
     */
    private function evaluate(array $variants, array $groups, array $queries, int $k): int
    {
        $exit = self::SUCCESS;
        $result = $this->runner->run($variants, $queries, $k);

        $this->renderTable($result['table'], $variants, $groups, $k, count($queries));
        $this->renderPerQuery($result['per_query'], $variants);

        $json = [
            'k' => $k,
            'index' => LabQueryBuilder::INDEX,
            'queries' => (string) $this->option('queries'),
            'variants' => array_map(static fn (LabVariant $v): string => $v->value, $variants),
            'groups' => $groups,
            'table' => $result['table'],
            'per_query' => $result['per_query'],
        ];

        if (in_array(LabVariant::Bm25, $variants, true)) {
            $unstable = $this->runner->unstableBm25Ties($queries, $k);
            $json['bm25_mixed_grade_ties'] = $unstable;

            if ($unstable === []) {
                $this->info('Strażnik remisów (bm25): brak remisów _score z różnymi ocenami w top-'.$k.'.');
            } else {
                $this->error('Wynik NIEDETERMINISTYCZNY (bm25): remis _score z różnymi ocenami w top-'.$k.' dla: '
                    .implode('; ', $unstable).'. Popraw reguły w semantic-queries.yaml (RUNBOOK #096).');
                $exit = self::TIE_UNSTABLE;
            }
        }

        if ($this->option('check-rank-eval')) {
            $json['rank_eval_check'] = [];

            foreach ($variants as $variant) {
                if (! $variant->isQueryExpressible()) {
                    continue;
                }

                $php = array_map(static fn (array $row): float => $row['ndcg'][$variant->value], $result['per_query']);
                $mismatches = $this->runner->rankEvalMismatches($variant, $queries, $php, $k);
                $json['rank_eval_check'][$variant->value] = $mismatches;

                if ($mismatches === []) {
                    $this->info("Kontrola _rank_eval ({$variant->value}): PHP i _rank_eval zgodne na ".count($queries).' zapytaniach (tolerancja 1e-6).');

                    continue;
                }

                foreach ($mismatches as $m) {
                    $this->error(sprintf('Kontrola _rank_eval (%s): „%s” — PHP %.9f, _rank_eval %.9f.', $variant->value, $m['query'], $m['php'], $m['rank_eval']));
                }
                $exit = self::FAILURE;
            }
        }

        if ($this->option('check-rrf')) {
            $mismatches = $this->runner->rrfMismatches($queries, $k);
            $json['rrf_check'] = $mismatches;

            if ($mismatches === []) {
                $this->info('Kontrola RRF: rrf-client i rrf-native mają ten sam top-'.$k.' (te same wyniki RRF na każdej pozycji; kolejność różni się co najwyżej wewnątrz remisów).');
            } else {
                foreach ($mismatches as $m) {
                    $this->error(sprintf('Kontrola RRF: „%s” — %s.', $m['query'], $m['reason']));
                }
                $exit = self::FAILURE;
            }
        }

        if ($this->option('recall')) {
            $recall = $this->runner->recall($queries, $k);
            $strict = array_sum(array_column($recall, 'strict')) / max(1, count($recall));
            $tieAware = array_sum(array_column($recall, 'tie_aware')) / max(1, count($recall));
            $json['recall'] = ['strict' => $strict, 'tie_aware' => $tieAware, 'per_query' => $recall];

            $this->info(sprintf(
                'recall@%d HNSW (knn na vec_float) względem knn-exact (brute force na vec_float, ten sam wektor zapytania): %.3f po id, %.3f z remisami na granicy (%d zapytań).',
                $k,
                $strict,
                $tieAware,
                count($recall),
            ));
        }

        $rounds = (int) $this->option('latency');
        if ($rounds > 0) {
            $latency = $this->runner->latency($variants, $queries, $rounds);
            $json['latency'] = $latency;

            $this->table(
                ['Wariant', 'Próbek', 'took p50 [ms]', 'took p99 [ms]', 'PHP p50 [ms]', 'PHP p99 [ms]'],
                array_map(static fn (string $name, array $l): array => [
                    $name,
                    $l['samples'],
                    number_format($l['took_p50'], 0),
                    number_format($l['took_p99'], 0),
                    number_format($l['php_p50'], 1),
                    number_format($l['php_p99'], 1),
                ], array_keys($latency), $latency),
            );
        }

        if ($path = $this->option('json')) {
            Storage::disk('local')->put($path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
            $this->info('Zapisano: storage/app/private/'.$path);
        }

        return $exit;
    }

    /**
     * @return list<LabVariant>|null
     */
    private function variants(): ?array
    {
        $names = $this->option('variant');
        if ($names === []) {
            return LabVariant::table();
        }

        $variants = [];
        foreach ($names as $name) {
            $variant = LabVariant::tryFrom($name);

            if ($variant === null || $variant === LabVariant::KnnExact) {
                $this->error("Nieznany wariant „{$name}” (dozwolone: ".implode(', ', array_map(static fn (LabVariant $v): string => $v->value, [...LabVariant::table(), LabVariant::Elser])).').');

                return null;
            }

            $variants[] = $variant;
        }

        return $variants;
    }

    /**
     * @param  array<string, array<string, float>>  $table
     * @param  list<LabVariant>  $variants
     * @param  list<string>  $groups
     */
    private function renderTable(array $table, array $variants, array $groups, int $k, int $queryCount): void
    {
        $this->line("nDCG@{$k} (średnia w grupie; {$queryCount} zapytań)");
        $this->table(
            ['Wariant', ...$groups],
            array_map(static fn (LabVariant $v): array => [
                $v->value,
                ...array_map(static fn (string $g): string => isset($table[$v->value][$g]) ? number_format($table[$v->value][$g], 3) : '-', $groups),
            ], $variants),
        );
    }

    /**
     * @param  list<array{query: string, group: string, ndcg: array<string, float>}>  $perQuery
     * @param  list<LabVariant>  $variants
     */
    private function renderPerQuery(array $perQuery, array $variants): void
    {
        $this->table(
            ['Zapytanie', 'Grupa', ...array_map(static fn (LabVariant $v): string => $v->value, $variants)],
            array_map(static fn (array $row): array => [
                $row['query'],
                $row['group'],
                ...array_map(static fn (LabVariant $v): string => number_format($row['ndcg'][$v->value], 3), $variants),
            ], $perQuery),
        );
    }

    private static function reason(string $body): string
    {
        $json = json_decode($body, true);

        return (string) ($json['error']['reason'] ?? $json['error']['root_cause'][0]['reason'] ?? mb_substr($body, 0, 200));
    }
}
