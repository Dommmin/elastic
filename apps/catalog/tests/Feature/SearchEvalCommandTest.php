<?php

use App\Services\Search\RelevanceJudgments;
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
