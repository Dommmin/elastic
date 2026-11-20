<?php

use App\Analytics\Simulation\Attractiveness;
use App\Models\Product;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * `analytics:judgments` bez żywego ES: sztuczny `_search` zwraca dla każdej
 * frazy przygotowaną listę (id, _score), z `min_score` — tylko dokumenty
 * o wyniku ≥ progu, jak ES.
 *
 * - „alfa”: 5 dokumentów po 7.0 + grupa remisu 30 po 5.0 → kandydat (grupa 30),
 * - „delta”: grupa remisu 25 po 3.0 → kandydat (grupa 25),
 * - „beta”: 22 różne wyniki → odpada (brak grupy ≥ 20),
 * - „gamma”: zero trafień → odpada,
 * - „omega”: 60 dokumentów po 2.0 → grupa w top 50 ma 50, ale cała 60 > limit
 *   ocenianej grupy (55 w teście) → odpada, bo nie dałoby się ocenić każdego
 *   dokumentu, który tasowanie może wynieść do top-k.
 *
 * @param  array<string, list<array{int, float}>>  $hitsByPhrase
 * @param  array<int, array<string, mixed>>  $requests
 */
function fakeJudgmentsEs(array $hitsByPhrase, array &$requests): ClientInterface
{
    return new class($hitsByPhrase, $requests) implements ClientInterface
    {
        /**
         * @param  array<string, list<array{int, float}>>  $hitsByPhrase
         * @param  array<int, array<string, mixed>>  $requests
         */
        public function __construct(private array $hitsByPhrase, private array &$requests) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $raw = (string) $request->getBody();
            $body = json_decode($raw, true) ?? [];
            $this->requests[] = ['query' => $request->getUri()->getQuery(), 'body' => $body];

            $hits = [];
            foreach ($this->hitsByPhrase as $phrase => $list) {
                if (str_contains($raw, '"'.$phrase.'"')) {
                    $hits = $list;
                }
            }

            if (isset($body['min_score'])) {
                $hits = array_values(array_filter($hits, fn (array $h) => $h[1] >= $body['min_score']));
            }

            $json = ['hits' => [
                'total' => ['value' => count($hits), 'relation' => 'eq'],
                'hits' => array_map(
                    fn (array $h) => ['_index' => 'products-v6', '_id' => (string) $h[0], '_score' => $h[1]],
                    array_slice($hits, 0, $body['size'] ?? 10),
                ),
            ]];

            return new Response(200, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode($json));
        }
    };
}

beforeEach(function () {
    $ids = Product::factory()->count(120)->create()->pluck('id')->all();

    $take = function (int $from, int $count, float $score) use ($ids): array {
        return array_map(fn (int $id) => [$id, $score], array_slice($ids, $from, $count));
    };

    $this->ids = $ids;
    $this->hits = [
        'alfa' => [...$take(0, 5, 7.0), ...$take(5, 30, 5.0)],
        'delta' => $take(35, 25, 3.0),
        'beta' => array_map(fn (int $i) => [$ids[60 + $i], 10.0 - $i * 0.1], range(0, 21)),
        'gamma' => [],
        'omega' => $take(0, 60, 2.0),
    ];
    $this->requests = [];

    $this->app->instance(Client::class, ClientBuilder::create()
        ->setHosts(['http://es.invalid:9200'])
        ->setHttpClient(fakeJudgmentsEs($this->hits, $this->requests))
        ->build());

    $this->output = tempnam(sys_get_temp_dir(), 'judgments').'.yaml';
    $this->travelTo('2026-11-20 12:00:00');
});

afterEach(function () {
    @unlink($this->output);
});

function runJudgments(array $options = []): string
{
    test()->artisan('analytics:judgments', [
        '--candidates' => 'alfa,beta,gamma,delta,omega',
        '--max-group' => 55,
        '--output' => test()->output,
        ...$options,
    ])->assertSuccessful();

    return file_get_contents(test()->output);
}

test('wybiera frazy z największą grupą remisu (≥ 20 w top 50), pomija bez remisów, bez trafień i grupy za duże do oceny', function () {
    $data = Yaml::parse(runJudgments(['--queries' => 10]));

    expect(array_column($data['queries'], 'query'))->toBe(['alfa', 'delta']);
});

test('--queries obcina listę do N największych grup', function () {
    $data = Yaml::parse(runJudgments(['--queries' => 1]));

    expect(array_column($data['queries'], 'query'))->toBe(['alfa']);
});

test('ocenia każdy dokument z top 50 (nie tylko grupę remisu) jako floor(a·4) obcięte do 0–3', function () {
    $data = Yaml::parse(runJudgments(['--seed' => 7]));

    $alfa = $data['queries'][0];
    expect($alfa['relevant'])->toHaveCount(1);

    $grades = $alfa['relevant'][0]['ids'];
    $expected = [];
    foreach (array_slice($this->ids, 0, 35) as $id) {
        $expected[$id] = min(3, (int) floor(Attractiveness::for($id, 7) * 4));
    }

    expect($grades)->toBe($expected)
        ->and(array_unique(array_values($grades)))->each->toBeBetween(0, 3);
});

test('plik jest deterministyczny przy ziarnie i zależy od ziarna', function () {
    $first = runJudgments(['--seed' => 1]);
    $second = runJudgments(['--seed' => 1]);
    $other = runJudgments(['--seed' => 2]);

    expect($first)->toBe($second)
        ->and($other)->not->toBe($first);
});

test('nagłówek mówi, że plik jest wygenerowany, czym, z jakim ziarnem, kiedy i co mierzy', function () {
    $content = runJudgments(['--seed' => 1, '--queries' => 10]);

    expect($content)
        ->toContain('# WYGENEROWANY')
        ->toContain('php artisan analytics:judgments --seed=1 --queries=10')
        ->toContain('2026-11-20')
        ->toContain('ukrytą prawdę');
});

test('ranking do ocen liczy bez popularności i ze stałą preferencją kopii shardów', function () {
    config(['services.search.popularity_boost' => 2.0]);

    runJudgments();

    foreach ($this->requests as $request) {
        expect(json_encode($request['body']))->not->toContain('rank_feature')
            ->and($request['query'])->toContain('preference=');
    }
});
