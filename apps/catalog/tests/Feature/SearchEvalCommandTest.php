<?php

use App\Console\Commands\SearchEvalCommand;
use App\Services\Search\RelevanceJudgments;
use App\Services\Search\TieGroupGuard;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * `search:eval --index=` bez żywego ES (rola `catalog` nie tworzy indeksów):
 * sztuczny transport zapisuje, dokąd poszło każde żądanie — zapytania, `_rank_eval`
 * i strażnik remisów mają trafiać w wskazany indeks fizyczny, a nie w alias.
 */
function fakeEvalEs(array &$log): ClientInterface
{
    return new class($log) implements ClientInterface
    {
        /** @param  array<int, string>  $log */
        public function __construct(private array &$log) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $path = $request->getUri()->getPath();
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $this->log[] = $request->getMethod().' '.$path;

            $json = match (true) {
                str_contains($path, '/_rank_eval') => [
                    'metric_score' => 0.8125,
                    'details' => array_combine(
                        array_column($body['requests'], 'id'),
                        array_map(static fn () => ['metric_score' => 0.8125, 'unrated_docs' => []], $body['requests']),
                    ),
                    'failures' => (object) [],
                ],
                str_contains($path, '/_alias') => ['products-v1' => ['aliases' => ['products-search' => (object) []]]],
                default => ['hits' => ['hits' => []]],
            };

            return new Response(200, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode($json));
        }
    };
}

beforeEach(function () {
    if (! is_file('/tests/relevance/queries.yaml')) {
        $this->markTestSkipped('Brak /tests/relevance/queries.yaml (poza kontenerem catalog-app).');
    }

    $this->log = [];
    $this->app->instance(Client::class, ClientBuilder::create()
        ->setHosts(['http://es.invalid:9200'])
        ->setHttpClient(fakeEvalEs($this->log))
        ->build());

    // Reguły z queries.yaml wymagałyby zaseedowanej bazy; tu liczy się adresowanie ES.
    $this->app->instance(RelevanceJudgments::class, new class extends RelevanceJudgments
    {
        public function resolve(array $rules): array
        {
            return ['1' => 1];
        }
    });
});

test('z --index zapytania, _rank_eval i strażnik idą do wskazanego indeksu, bez rozwiązywania aliasu', function () {
    $this->artisan('search:eval', ['--index' => 'products-v2'])
        ->expectsOutputToContain('NDCG=0.8125')
        ->assertSuccessful();

    expect($this->log)->not->toBeEmpty()
        ->and(collect($this->log)->filter(fn ($l) => str_contains($l, '_alias')))->toBeEmpty()
        ->and(collect($this->log)->filter(fn ($l) => str_contains($l, 'products-search')))->toBeEmpty()
        ->and(collect($this->log)->filter(fn ($l) => str_contains($l, '_rank_eval') && str_contains($l, '/products-v2/')))->not->toBeEmpty()
        ->and(collect($this->log)->filter(fn ($l) => str_contains($l, '/_search') && str_contains($l, '/products-v2/')))->not->toBeEmpty();
});

test('bez --index zachowanie bez zmian: alias rozwiązany, zapytania na products-search', function () {
    $this->artisan('search:eval')
        ->expectsOutputToContain('NDCG=0.8125')
        ->assertSuccessful();

    expect(collect($this->log)->filter(fn ($l) => str_contains($l, '_alias')))->not->toBeEmpty()
        ->and(collect($this->log)->filter(fn ($l) => str_contains($l, '_rank_eval') && str_contains($l, '/products-search/')))->not->toBeEmpty();
});

test('sam remis z różnymi ocenami kończy się kodem 2 (TIE_UNSTABLE), nie 1 — skrypt reindeksu odróżnia go od awarii', function () {
    $this->app->instance(TieGroupGuard::class, new class(app(Client::class)) extends TieGroupGuard
    {
        public function hasMixedGradeTie(string $index, array $query, array $grades, int $k): bool
        {
            return true;
        }
    });

    $this->artisan('search:eval', ['--index' => 'products-v2'])
        ->expectsOutputToContain('NDCG=0.8125')
        ->expectsOutputToContain('NIEDETERMINISTYCZNY')
        ->assertExitCode(SearchEvalCommand::TIE_UNSTABLE);
});

test('błędy _rank_eval kończą się kodem 1 także przy remisie', function () {
    $this->app->instance(TieGroupGuard::class, new class(app(Client::class)) extends TieGroupGuard
    {
        public function hasMixedGradeTie(string $index, array $query, array $grades, int $k): bool
        {
            return true;
        }
    });
    $log = [];
    $this->app->instance(Client::class, ClientBuilder::create()
        ->setHosts(['http://es.invalid:9200'])
        ->setHttpClient(new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $json = str_contains($request->getUri()->getPath(), '/_rank_eval')
                    ? ['metric_score' => 0.5, 'details' => (object) [], 'failures' => ['q0' => ['error' => 'boom']]]
                    : ['hits' => ['hits' => []]];

                return new Response(200, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode($json));
            }
        })
        ->build());

    $this->artisan('search:eval', ['--index' => 'products-v2'])
        ->expectsOutputToContain('Błędy _rank_eval')
        ->assertExitCode(1);
});
