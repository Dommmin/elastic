<?php

namespace App\Services\Search\Lab;

use App\Services\Search\TieGroupGuard;
use Elastic\Elasticsearch\Client;

/**
 * Wykonuje warianty laboratorium na węźle es-lab i liczy nDCG@k w PHP (`Ndcg`) —
 * jednym licznikiem dla wszystkich wariantów, także tych, których `_rank_eval` nie przyjmie
 * (RRF). Klient `es.lab` trafia tylko tutaj (AppServiceProvider), sklep go nie widzi.
 *
 * @phpstan-type LabQuery array{query: string, group: string, ratings: array<string, int>}
 * @phpstan-type Ranked array{ids: list<string>, scores: list<float>, took: int}
 */
class LabEvalRunner
{
    /** Tolerancja porównania wyników RRF: ES zwraca `_score` jako float32. */
    private const RRF_EPSILON = 1e-6;

    public function __construct(
        private readonly Client $client,
        private readonly LabQueryBuilder $builder,
    ) {}

    /**
     * @param  list<LabVariant>  $variants
     * @param  list<LabQuery>  $queries
     * @return array{table: array<string, array<string, float>>, per_query: list<array{query: string, group: string, ndcg: array<string, float>, top: array<string, list<string>>}>}
     */
    public function run(array $variants, array $queries, int $k): array
    {
        $perQuery = [];
        /** @var array<string, array<string, list<float>>> $byGroup */
        $byGroup = [];

        foreach ($queries as $entry) {
            $row = ['query' => $entry['query'], 'group' => $entry['group'], 'ndcg' => [], 'top' => []];

            foreach ($variants as $variant) {
                $ids = $this->ranked($variant, $entry['query'], $k)['ids'];
                $ndcg = Ndcg::atK($ids, $entry['ratings'], $k);

                $row['ndcg'][$variant->value] = $ndcg;
                $row['top'][$variant->value] = $ids;
                $byGroup[$variant->value][$entry['group']][] = $ndcg;
            }

            $perQuery[] = $row;
        }

        $table = [];
        foreach ($variants as $variant) {
            foreach ($byGroup[$variant->value] ?? [] as $group => $scores) {
                $table[$variant->value][$group] = array_sum($scores) / count($scores);
            }
        }

        return ['table' => $table, 'per_query' => $perQuery];
    }

    /**
     * Top-`size` wariantu. `rrf-client`: dwa `_search` (bm25 i knn, po `rank_window_size`
     * trafień), fuzja w PHP; `took` to suma obu.
     *
     * @return Ranked
     */
    public function ranked(LabVariant $variant, string $q, int $size = LabQueryBuilder::K): array
    {
        if ($variant === LabVariant::RrfClient) {
            $text = $this->search(['query' => $this->builder->textQuery($q), 'size' => LabQueryBuilder::RANK_WINDOW, '_source' => false]);
            $knn = $this->search(['query' => $this->builder->knnQuery($q), 'size' => LabQueryBuilder::RANK_WINDOW, '_source' => false]);
            $rankings = [$text['ids'], $knn['ids']];
            $scores = Rrf::scores($rankings, LabQueryBuilder::RANK_CONSTANT, LabQueryBuilder::RANK_WINDOW);
            $ids = array_slice(Rrf::fuse($rankings, LabQueryBuilder::RANK_CONSTANT, LabQueryBuilder::RANK_WINDOW), 0, $size);

            return [
                'ids' => $ids,
                'scores' => array_map(static fn (string $id): float => $scores[$id], $ids),
                'took' => $text['took'] + $knn['took'],
            ];
        }

        if ($variant === LabVariant::KnnExact) {
            return $this->search($this->builder->knnExactBody($this->queryVector($q), $size));
        }

        return $this->search($this->builder->body($variant, $q, $size));
    }

    /**
     * Wektor zapytania z endpointu `lab-e5` (`input_type: search` → prefiks `query: `).
     *
     * @return list<float>
     */
    public function queryVector(string $q): array
    {
        $response = $this->client->inference()->textEmbedding([
            'inference_id' => LabQueryBuilder::INFERENCE_ID,
            'body' => ['input' => $q, 'input_type' => 'search'],
        ])->asArray();

        return $response['text_embedding'][0]['embedding'];
    }

    /**
     * Kontrola własnego licznika: nDCG z PHP kontra `_rank_eval` (`dcg`, `normalize: true`)
     * dla wariantu wyrażalnego jako `query`.
     *
     * @param  list<LabQuery>  $queries
     * @param  array<int, float>  $phpScores  nDCG z PHP, indeks jak w `$queries`
     * @return list<array{query: string, php: float, rank_eval: float}> rozbieżności > 1e-6
     */
    public function rankEvalMismatches(LabVariant $variant, array $queries, array $phpScores, int $k): array
    {
        $requests = [];
        foreach ($queries as $i => $entry) {
            $requests[] = [
                'id' => "q{$i}",
                'request' => ['query' => $this->builder->body($variant, $entry['query'], $k)['query']],
                'ratings' => array_map(
                    static fn (int|string $id, int $grade) => ['_index' => LabQueryBuilder::INDEX, '_id' => (string) $id, 'rating' => $grade],
                    array_keys($entry['ratings']),
                    $entry['ratings'],
                ),
            ];
        }

        $response = $this->client->rankEval([
            'index' => LabQueryBuilder::INDEX,
            'body' => ['requests' => $requests, 'metric' => ['dcg' => ['k' => $k, 'normalize' => true]]],
        ])->asArray();

        $mismatches = [];
        foreach ($queries as $i => $entry) {
            $es = (float) ($response['details']["q{$i}"]['metric_score'] ?? NAN);

            if (! (abs($es - $phpScores[$i]) <= 1e-6)) {
                $mismatches[] = ['query' => $entry['query'], 'php' => $phpScores[$i], 'rank_eval' => $es];
            }
        }

        return $mismatches;
    }

    /**
     * `rrf-client` kontra `rrf-native`: na każdej pozycji top-k ten sam wynik RRF, a każdy
     * dokument z ES ma w PHP tę samą sumę. Różna kolejność dopuszczalna tylko w remisie
     * (ta sama suma) — tam ES rozstrzyga numerem dokumentu Lucene, PHP mniejszym id.
     *
     * @param  list<LabQuery>  $queries
     * @return list<array{query: string, reason: string}>
     */
    public function rrfMismatches(array $queries, int $k): array
    {
        $mismatches = [];

        foreach ($queries as $entry) {
            $q = $entry['query'];
            $text = $this->search(['query' => $this->builder->textQuery($q), 'size' => LabQueryBuilder::RANK_WINDOW, '_source' => false]);
            $knn = $this->search(['query' => $this->builder->knnQuery($q), 'size' => LabQueryBuilder::RANK_WINDOW, '_source' => false]);
            $clientScores = Rrf::scores([$text['ids'], $knn['ids']], LabQueryBuilder::RANK_CONSTANT, LabQueryBuilder::RANK_WINDOW);
            $client = array_slice(Rrf::fuse([$text['ids'], $knn['ids']], LabQueryBuilder::RANK_CONSTANT, LabQueryBuilder::RANK_WINDOW), 0, $k);
            $native = $this->search($this->builder->body(LabVariant::RrfNative, $q, $k));

            if (count($native['ids']) !== count($client)) {
                $mismatches[] = ['query' => $q, 'reason' => sprintf('liczba trafień: native %d, client %d', count($native['ids']), count($client))];

                continue;
            }

            foreach ($native['ids'] as $pos => $id) {
                $expected = $clientScores[$id] ?? null;

                if ($expected === null || abs($expected - $native['scores'][$pos]) > self::RRF_EPSILON) {
                    $mismatches[] = ['query' => $q, 'reason' => sprintf('dokument %s: native %.9f, client %s', $id, $native['scores'][$pos], $expected === null ? 'brak' : sprintf('%.9f', $expected))];

                    continue 2;
                }

                if (abs($clientScores[$client[$pos]] - $native['scores'][$pos]) > self::RRF_EPSILON) {
                    $mismatches[] = ['query' => $q, 'reason' => sprintf('pozycja %d: native %s (%.9f), client %s (%.9f)', $pos + 1, $id, $native['scores'][$pos], $client[$pos], $clientScores[$client[$pos]])];

                    continue 2;
                }
            }
        }

        return $mismatches;
    }

    /**
     * recall@k HNSW (`knn` na `vec_float`) względem dokładnego kNN (`knn-exact`, też `vec_float`)
     * z tym samym wektorem zapytania. `strict` liczy wspólne id; `tie_aware` uznaje też dokument
     * HNSW, którego podobieństwo jest równe k-temu dokładnemu (duplikaty mają identyczne wektory,
     * więc na granicy jest remis i „brakujące” id bywa tylko innym członkiem remisu).
     *
     * @param  list<LabQuery>  $queries
     * @return list<array{query: string, strict: float, tie_aware: float}>
     */
    public function recall(array $queries, int $k): array
    {
        $out = [];

        foreach ($queries as $entry) {
            $vector = $this->queryVector($entry['query']);
            $hnsw = $this->search($this->builder->knnFloatBody($vector, $k));
            $exact = $this->search($this->builder->knnExactBody($vector, $k));

            // knn (cosine) daje (1 + cos) / 2, script_score daje cos + 1 — sprowadzamy do cos.
            $boundary = end($exact['scores']) - 1.0;
            $tieAware = 0;
            foreach ($hnsw['scores'] as $score) {
                if (2 * $score - 1 >= $boundary - 1e-6) {
                    $tieAware++;
                }
            }

            $out[] = [
                'query' => $entry['query'],
                'strict' => count(array_intersect($hnsw['ids'], $exact['ids'])) / $k,
                'tie_aware' => min($tieAware, $k) / $k,
            ];
        }

        return $out;
    }

    /**
     * Latencja: `$warmup` rozgrzewkowych żądań na wariant, potem `$rounds` przebiegów po
     * wszystkich zapytaniach. `took` z ES i czas całkowity po stronie PHP (z siecią i JSON-em).
     * Wiersz `inference` mierzy samo `_inference/text_embedding/lab-e5` (wektor zapytania).
     *
     * UWAGA: wdrożenie modelu w ML ma cache inferencji (`cache_size`, domyślnie rozmiar modelu),
     * więc powtórzone teksty zapytań trafiają w cache — wiersze wariantów i `inference` to czasy
     * „rozgrzane”. Wiersz `inference-cold` dokleja do każdego tekstu unikalny znacznik (losowy na
     * przebieg + numer próbki), więc każde wywołanie liczy wektor od nowa.
     *
     * @param  list<LabVariant>  $variants
     * @param  list<LabQuery>  $queries
     * @return array<string, array{samples: int, took_p50: float, took_p99: float, php_p50: float, php_p99: float}>
     */
    public function latency(array $variants, array $queries, int $rounds, int $warmup = 5): array
    {
        $out = [];
        $texts = array_column($queries, 'query');

        $nonce = bin2hex(random_bytes(4));
        $measure = function (callable $call, bool $unique = false) use ($texts, $rounds, $warmup, $nonce): array {
            for ($i = 0; $i < $warmup; $i++) {
                $call($texts[$i % count($texts)]);
            }

            $took = [];
            $php = [];
            $n = 0;
            for ($r = 0; $r < $rounds; $r++) {
                foreach ($texts as $text) {
                    $text = $unique ? sprintf('%s %s-%d', $text, $nonce, $n++) : $text;
                    $start = hrtime(true);
                    $took[] = (float) $call($text);
                    $php[] = (hrtime(true) - $start) / 1e6;
                }
            }

            return [
                'samples' => count($php),
                'took_p50' => self::percentile($took, 50),
                'took_p99' => self::percentile($took, 99),
                'php_p50' => self::percentile($php, 50),
                'php_p99' => self::percentile($php, 99),
            ];
        };

        foreach ($variants as $variant) {
            $out[$variant->value] = $measure(fn (string $q): int => $this->ranked($variant, $q)['took']);
        }

        $inference = function (string $q): int {
            $this->queryVector($q);

            return 0;
        };
        $out['inference'] = $measure($inference);
        $out['inference-cold'] = $measure($inference, unique: true);

        return $out;
    }

    /**
     * Strażnik remisów `search:eval` na `bm25`: zapytania, w których top-k ma grupę remisu
     * `_score` z różnymi ocenami (kolejność rozstrzygałby Lucene, nie trafność).
     *
     * @param  list<LabQuery>  $queries
     * @return list<string>
     */
    public function unstableBm25Ties(array $queries, int $k): array
    {
        $guard = new TieGroupGuard($this->client);

        return array_values(array_map(
            static fn (array $entry): string => $entry['query'],
            array_filter($queries, fn (array $entry): bool => $guard->hasMixedGradeTie(
                LabQueryBuilder::INDEX,
                $this->builder->textQuery($entry['query']),
                $entry['ratings'],
                $k,
            )),
        ));
    }

    /**
     * Percentyl metodą najbliższej pozycji (nearest-rank).
     *
     * @param  list<float>  $values
     */
    public static function percentile(array $values, int $p): float
    {
        sort($values);

        return $values[max(0, (int) ceil($p / 100 * count($values)) - 1)];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return Ranked
     */
    private function search(array $body): array
    {
        $response = $this->client->search(['index' => LabQueryBuilder::INDEX, 'body' => $body])->asArray();
        $hits = $response['hits']['hits'];

        return [
            'ids' => array_map(static fn (array $hit): string => (string) $hit['_id'], $hits),
            'scores' => array_map(static fn (array $hit): float => (float) $hit['_score'], $hits),
            'took' => (int) ($response['took'] ?? 0),
        ];
    }
}
