<?php

use App\Services\Search\TieGroupGuard;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Strażnik remisów `search:eval` bez żywego ES: sztuczny transport HTTP symuluje
 * indeks z jedną grupą remisu `_score` większą niż dawny limit 1000 trafień
 * i okno wyników (PIT + `search_after`, strony po `size`). Użytkownik `catalog`
 * nie ma uprawnień do tworzenia indeksów, więc test na jednorazowym indeksie
 * nie jest możliwy; na żywym 300 tys. indeksie guard sprawdza `make eval`.
 */
function fakeTieEs(int $groupSize): ClientInterface
{
    return new class($groupSize) implements ClientInterface
    {
        public function __construct(private readonly int $groupSize) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $path = $request->getUri()->getPath();
            $body = json_decode((string) $request->getBody(), true) ?? [];

            $json = match (true) {
                str_ends_with($path, '/_pit') && $request->getMethod() === 'POST' => ['id' => 'pit-1'],
                str_ends_with($path, '/_pit') => ['succeeded' => true, 'num_freed' => 1],
                isset($body['pit']) => $this->page($body),
                default => $this->hits(range(1, $body['size'])),
            };

            return new Response(200, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode($json));
        }

        /** @param  array<string, mixed>  $body */
        private function page(array $body): array
        {
            $after = $body['search_after'][1] ?? 0;
            $ids = range($after + 1, min($after + $body['size'], $this->groupSize));

            return $this->hits($ids === [] || $ids[0] > $this->groupSize ? [] : $ids);
        }

        /** @param  array<int, int>  $ids */
        private function hits(array $ids): array
        {
            return ['hits' => ['hits' => array_map(
                static fn (int $id) => ['_id' => (string) $id, '_score' => 1.5, 'sort' => [1.5, $id]],
                $ids,
            )]];
        }
    };
}

function tieGuard(int $groupSize): TieGroupGuard
{
    $client = ClientBuilder::create()
        ->setHosts(['http://es.invalid:9200'])
        ->setHttpClient(fakeTieEs($groupSize))
        ->build();

    return new TieGroupGuard($client);
}

test('jednolite oceny w grupie remisu większej niż 1000 nie są niestabilne', function () {
    $grades = array_fill(1, 12_000, 1);

    expect(tieGuard(12_000)->hasMixedGradeTie('products-search', ['match_all' => (object) []], $grades, 10))->toBeFalse();
});

test('inna ocena członka grupy remisu daleko za dawnym limitem 1000 i oknem 10 000 jest wykrywana', function () {
    $grades = array_fill(1, 12_000, 1);
    $grades[11_500] = 3;

    expect(tieGuard(12_000)->hasMixedGradeTie('products-search', ['match_all' => (object) []], $grades, 10))->toBeTrue();
});

test('oceny dokumentów poza top-k mają znaczenie, gdy grupa wystaje poza k', function () {
    // Top-10 to same jedynki; jedyna inna ocena leży w ogonie grupy (poza top-k).
    $grades = array_fill(1, 2_000, 1);
    $grades[2_000] = 2;

    expect(tieGuard(2_000)->hasMixedGradeTie('products-search', ['match_all' => (object) []], $grades, 10))->toBeTrue();
});
