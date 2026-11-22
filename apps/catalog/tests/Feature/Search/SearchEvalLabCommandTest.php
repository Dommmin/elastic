<?php

use App\Services\Search\Lab\LabJudgments;
use App\Services\Search\Lab\Ndcg;
use App\Services\Search\Lab\Rrf;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * `search:eval:lab` bez żywego węzła lab: sztuczny transport odpowiada na `_search`
 * zależnie od kształtu ciała (multi_match → bm25, knn → wektory, retriever → RRF w ES)
 * i zapisuje log żądań. Oceny podstawia sztuczne `LabJudgments` (bez bazy).
 *
 * bm25 zwraca [a, b, c], knn zwraca [c, b]. Oceny: q1 (lexical) a=3, b=1; q2 (descriptive) b=3.
 *
 * @param  array{mode?: string, rank_eval?: float}  $options
 */
function fakeLabEvalEs(array &$log, array $options = []): ClientInterface
{
    return new class($log, $options) implements ClientInterface
    {
        public function __construct(private array &$log, private array $options) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $path = $request->getUri()->getPath();
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $this->log[] = $request->getMethod().' '.$path;

            if (($this->options['mode'] ?? null) === 'down') {
                throw new class('Connection refused') extends RuntimeException implements NetworkExceptionInterface
                {
                    public function getRequest(): RequestInterface
                    {
                        return new Request('GET', 'http://es.invalid');
                    }
                };
            }

            if (($this->options['mode'] ?? null) === 'basic' && isset($body['retriever'])) {
                return $this->json(403, ['error' => [
                    'root_cause' => [['type' => 'security_exception', 'reason' => 'current license is non-compliant for [rrf]', 'license.expired.feature' => 'rrf']],
                    'type' => 'security_exception', 'reason' => 'current license is non-compliant for [rrf]', 'license.expired.feature' => 'rrf',
                ], 'status' => 403]);
            }

            $hits = static fn (array $ids): array => ['took' => 3, 'hits' => ['hits' => array_map(
                static fn (string $id, int $i) => ['_index' => 'products-lab-v1', '_id' => $id, '_score' => 10.0 - $i],
                $ids,
                array_keys($ids),
            )]];

            $json = match (true) {
                str_contains($path, '/_rank_eval') => [
                    'metric_score' => $this->options['rank_eval'] ?? 0.5,
                    'details' => array_combine(
                        array_column($body['requests'], 'id'),
                        array_map(fn () => ['metric_score' => $this->options['rank_eval'] ?? 0.5, 'unrated_docs' => []], $body['requests']),
                    ),
                    'failures' => (object) [],
                ],
                str_contains($path, '/_pit') && $request->getMethod() === 'POST' => ['id' => 'pit-1'],
                str_contains($path, '/_pit') => ['succeeded' => true, 'num_freed' => 1],
                isset($body['pit']) => ['hits' => ['hits' => []]],
                // strażnik remisów (TieGroupGuard): pełne top-k o różnych wynikach — każda grupa ma jedną ocenę
                ($body['track_total_hits'] ?? null) === false => $hits(['a', 'b', 'c', 'x1', 'x2', 'x3', 'x4', 'x5', 'x6', 'x7']),
                isset($body['retriever']) => $hits(['a', 'c', 'b']),
                isset($body['query']['knn']) => $hits(['c', 'b']),
                isset($body['query']['bool']) => $hits(['b', 'a']),
                default => $hits(['a', 'b', 'c']),
            };

            return $this->json(200, $json);
        }

        private function json(int $status, array $json): ResponseInterface
        {
            return new Response($status, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode($json));
        }
    };
}

function useFakeLabEvalEs(array &$log, array $options = []): void
{
    app()->instance('es.lab', ClientBuilder::create()
        ->setHosts(['http://es-lab.invalid:9200'])
        ->setHttpClient(fakeLabEvalEs($log, $options))
        ->build());
}

beforeEach(function () {
    $this->log = [];
    useFakeLabEvalEs($this->log);

    $this->queriesFile = tempnam(sys_get_temp_dir(), 'lab-queries');
    file_put_contents($this->queriesFile, "queries: []\n");

    $this->app->instance(LabJudgments::class, new class extends LabJudgments
    {
        public function load(string $path): array
        {
            return [
                ['query' => 'q1', 'group' => 'lexical', 'ratings' => ['a' => 3, 'b' => 1]],
                ['query' => 'q2', 'group' => 'descriptive', 'ratings' => ['b' => 3]],
            ];
        }
    });
});

afterEach(function () {
    @unlink($this->queriesFile);
});

test('prints a variant by group table', function () {
    $bm25Lexical = number_format(Ndcg::atK(['a', 'b', 'c'], ['a' => 3, 'b' => 1], 10), 3);
    $bm25Descriptive = number_format(Ndcg::atK(['a', 'b', 'c'], ['b' => 3], 10), 3);
    $knnLexical = number_format(Ndcg::atK(['c', 'b'], ['a' => 3, 'b' => 1], 10), 3);

    expect([$bm25Lexical, $bm25Descriptive, $knnLexical])->toBe(['1.000', '0.631', '0.083']);

    $this->artisan('search:eval:lab', [
        '--variant' => ['bm25', 'knn'],
        '--group' => ['lexical', 'descriptive'],
        '--queries' => $this->queriesFile,
    ])
        ->expectsOutputToContain('| bm25    | 1.000   | 0.631       |')
        ->expectsOutputToContain('| knn     | 0.083   | 0.631       |')
        ->assertSuccessful();
});

test('rrf-client asks ES for bm25 and knn and fuses in PHP', function () {
    $expected = number_format(Ndcg::atK(Rrf::fuse([['a', 'b', 'c'], ['c', 'b']]), ['a' => 3, 'b' => 1], 10), 3);

    $this->artisan('search:eval:lab', ['--variant' => ['rrf-client'], '--group' => ['lexical'], '--queries' => $this->queriesFile])
        ->expectsOutputToContain("| rrf-client | {$expected}")
        ->assertSuccessful();

    expect(collect($this->log)->filter(fn ($l) => str_ends_with($l, '/products-lab-v1/_search'))->count())->toBe(2);
});

test('refuses rrf-native on a basic license with one sentence', function () {
    useFakeLabEvalEs($this->log, ['mode' => 'basic']);

    $this->artisan('search:eval:lab', ['--variant' => ['rrf-native'], '--queries' => $this->queriesFile])
        ->expectsOutputToContain('make lab-setup')
        ->doesntExpectOutputToContain('Stack trace')
        ->doesntExpectOutputToContain('#0 ')
        ->assertExitCode(1);
});

test('says the lab node is down', function () {
    useFakeLabEvalEs($this->log, ['mode' => 'down']);

    $this->artisan('search:eval:lab', ['--variant' => ['bm25'], '--queries' => $this->queriesFile])
        ->expectsOutputToContain('make lab-up')
        ->assertExitCode(1);
});

test('--check-rank-eval fails when PHP and _rank_eval differ by more than 1e-6', function () {
    // PHP: bm25 dla q1 = 1.000, sztuczny _rank_eval = 0.5
    $this->artisan('search:eval:lab', ['--variant' => ['bm25'], '--group' => ['lexical'], '--check-rank-eval' => true, '--queries' => $this->queriesFile])
        ->expectsOutputToContain('Kontrola _rank_eval (bm25): „q1” — PHP 1.000000000, _rank_eval 0.500000000.')
        ->assertExitCode(1);
});

test('--check-rank-eval passes when the numbers agree', function () {
    useFakeLabEvalEs($this->log, ['rank_eval' => 1.0]);

    $this->artisan('search:eval:lab', ['--variant' => ['bm25'], '--group' => ['lexical'], '--check-rank-eval' => true, '--queries' => $this->queriesFile])
        ->expectsOutputToContain('zgodne')
        ->assertSuccessful();
});

test('--json writes the same numbers as the table', function () {
    Storage::fake('local');

    $this->artisan('search:eval:lab', [
        '--variant' => ['bm25', 'knn'],
        '--queries' => $this->queriesFile,
        '--json' => 'lab/eval-test.json',
    ])
        ->expectsOutputToContain('| bm25    | 1.000   | 0.631       |')
        ->assertSuccessful();

    $json = json_decode(Storage::disk('local')->get('lab/eval-test.json'), true);

    expect(number_format($json['table']['bm25']['lexical'], 3))->toBe('1.000')
        ->and(number_format($json['table']['bm25']['descriptive'], 3))->toBe('0.631')
        ->and(number_format($json['table']['knn']['lexical'], 3))->toBe('0.083')
        ->and($json['per_query'])->toHaveCount(2)
        ->and($json['k'])->toBe(10);
});

test('unknown variant is refused', function () {
    $this->artisan('search:eval:lab', ['--variant' => ['nope'], '--queries' => $this->queriesFile])
        ->expectsOutputToContain('nope')
        ->assertExitCode(1);
});

test('--vector-field and --knn-filter are accepted for the knn variant', function () {
    $this->artisan('search:eval:lab', ['--variant' => ['knn'], '--vector-field' => 'vec_int8', '--knn-filter' => 'post', '--queries' => $this->queriesFile])
        ->assertSuccessful();
});

test('unknown --vector-field and --knn-filter are refused', function () {
    $this->artisan('search:eval:lab', ['--variant' => ['knn'], '--vector-field' => 'vec_nope', '--queries' => $this->queriesFile])
        ->expectsOutputToContain('vec_nope')
        ->assertFailed();

    $this->artisan('search:eval:lab', ['--variant' => ['knn'], '--knn-filter' => 'nope', '--queries' => $this->queriesFile])
        ->expectsOutputToContain('nope')
        ->assertFailed();
});
