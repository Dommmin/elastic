<?php

use App\Services\Search\RelevanceJudgments;
use App\Services\Search\TieGroupGuard;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * `search:eval --tie-shuffles=N` bez żywego ES: sztuczny `_rank_eval` zwraca
 * wynik zależny od ziarna tasowania (przebieg i → ziarno i), żeby sprawdzić
 * mean/min/max, a zapisane ciała żądań — że tasowanie dotyka TYLKO remisów.
 *
 * q0 („remisy”): nDCG = ziarno / 10 (0.1 … 0.5) — kolejność w remisie zmienia wynik.
 * q1 („bez remisów”): nDCG stałe 0.9 — tasowanie nic nie zmienia, więc min = max.
 */
function fakeShuffleEs(array &$bodies): ClientInterface
{
    return new class($bodies) implements ClientInterface
    {
        /** @param  array<int, array<string, mixed>>  $bodies */
        public function __construct(private array &$bodies) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $path = $request->getUri()->getPath();
            $body = json_decode((string) $request->getBody(), true) ?? [];

            $json = match (true) {
                str_contains($path, '/_rank_eval') => $this->rankEval($body),
                default => ['hits' => ['hits' => []]],
            };

            return new Response(200, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode($json));
        }

        /** @param  array<string, mixed>  $body */
        private function rankEval(array $body): array
        {
            $this->bodies[] = $body;
            $seed = $body['requests'][0]['request']['sort'][1]['_script']['script']['params']['seed'] ?? null;
            $q0 = $seed === null ? 0.25 : $seed / 10;

            return [
                'metric_score' => ($q0 + 0.9) / 2,
                'details' => [
                    'q0' => ['metric_score' => $q0, 'unrated_docs' => []],
                    'q1' => ['metric_score' => 0.9, 'unrated_docs' => []],
                ],
                'failures' => (object) [],
            ];
        }
    };
}

beforeEach(function () {
    $this->bodies = [];
    $this->app->instance(Client::class, ClientBuilder::create()
        ->setHosts(['http://es.invalid:9200'])
        ->setHttpClient(fakeShuffleEs($this->bodies))
        ->build());

    $this->app->instance(RelevanceJudgments::class, new class extends RelevanceJudgments
    {
        public function resolve(array $rules): array
        {
            return ['1' => 1];
        }
    });

    $this->queriesFile = tempnam(sys_get_temp_dir(), 'queries').'.yaml';
    file_put_contents($this->queriesFile, <<<'YAML'
        queries:
          - query: "remisy"
            relevant:
              - { id: 1, grade: 1 }
          - query: "bez remisów"
            relevant:
              - { id: 1, grade: 1 }
        YAML);
});

afterEach(function () {
    @unlink($this->queriesFile);
});

test('--tie-shuffles=5 robi 5 przebiegów _rank_eval z ziarnami 1..5 i wypisuje mean/min/max', function () {
    $this->artisan('search:eval', ['--index' => 'products-v6', '--queries' => $this->queriesFile, '--tie-shuffles' => 5])
        ->expectsTable(['Zapytanie', 'nDCG@10 średnio', 'min', 'max', 'Nieocenione w top-k (maks.)'], [
            ['remisy', '0.300', '0.100', '0.500', 0],
            // Bez remisów tasowanie nic nie zmienia: min = max.
            ['bez remisów', '0.900', '0.900', '0.900', 0],
        ])
        // Łącznie: średnie przebiegów to (s/10 + 0.9)/2 dla s = 1..5 → 0.50 … 0.70, średnia 0.60.
        ->expectsOutputToContain('Średnie nDCG@10 z 5 przebiegów: 0.600 (min 0.500, max 0.700)')
        ->expectsOutputToContain('NDCG=0.6')
        ->expectsOutputToContain('NDCG_MIN=0.5')
        ->expectsOutputToContain('NDCG_MAX=0.7')
        ->assertSuccessful();

    $seeds = array_map(
        fn (array $body) => $body['requests'][0]['request']['sort'][1]['_script']['script']['params']['seed'],
        $this->bodies,
    );

    expect($seeds)->toBe([1, 2, 3, 4, 5]);
});

test('tasowanie rozstrzyga tylko remisy: zapytanie bez zmian, pierwszy klucz sortowania to _score malejąco', function () {
    $this->artisan('search:eval', ['--index' => 'products-v6', '--queries' => $this->queriesFile])->assertSuccessful();
    $this->artisan('search:eval', ['--index' => 'products-v6', '--queries' => $this->queriesFile, '--tie-shuffles' => 3])->assertSuccessful();

    $plain = $this->bodies[0];
    $shuffled = array_slice($this->bodies, 1);

    expect($shuffled)->toHaveCount(3);

    expect($plain['requests'][0]['request'])->not->toHaveKey('sort');

    foreach ($shuffled as $body) {
        foreach ($body['requests'] as $i => $request) {
            // Część punktująca identyczna jak bez tasowania — `_score` liczony bit w bit tak samo,
            // więc dokumenty o różnych wynikach nie mogą zamienić się miejscami.
            expect($request['request']['query'])->toBe($plain['requests'][$i]['request']['query'])
                ->and($request['request']['sort'][0])->toBe(['_score' => 'desc'])
                ->and($request['request']['sort'][1]['_script']['order'])->toBe('asc')
                ->and($request['request']['sort'])->toHaveCount(2);
        }
    }
});

test('przy --tie-shuffles strażnik remisów nie zatrzymuje evala (remisy są celowo tasowane)', function () {
    $this->app->instance(TieGroupGuard::class, new class(app(Client::class)) extends TieGroupGuard
    {
        public function hasMixedGradeTie(string $index, array $query, array $grades, int $k): bool
        {
            throw new RuntimeException('strażnik nie powinien być wołany przy tasowaniu');
        }
    });

    $this->artisan('search:eval', ['--index' => 'products-v6', '--queries' => $this->queriesFile, '--tie-shuffles' => 2])
        ->assertSuccessful();
});

test('brak pliku z --queries kończy się błędem', function () {
    $this->artisan('search:eval', ['--index' => 'products-v6', '--queries' => '/nie/ma/takiego.yaml'])
        ->expectsOutputToContain('Brak pliku zapytań kontrolnych: /nie/ma/takiego.yaml')
        ->assertExitCode(1);
});
