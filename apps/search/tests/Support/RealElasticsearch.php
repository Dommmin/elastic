<?php

namespace App\Tests\Support;

use App\Service\CatalogProjectionClient;
use App\Service\ElasticsearchClientFactory;
use App\Tracing\TraceContext;
use Elastic\Elasticsearch\Client;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Wspólne klocki testów migracji (ETAP 10) na PRAWDZIWYM Elasticsearchu:
 * klient albo pominięcie testu, mapowania z repo, hurtowe dokumenty
 * z wersją zewnętrzną i fałszywy catalog z licznikiem produktów.
 *
 * Indeksy i aliasy zawsze `products-*-<uniqid>` (rola search_service),
 * sprzątane w tearDown po jawnych nazwach.
 */
trait RealElasticsearch
{
    private const MAPPINGS_DIR = '/infra/elasticsearch/mappings';

    private function elasticsearchOrSkip(): Client
    {
        $host = $this->env('ELASTICSEARCH_HOST');
        if ($host === '') {
            self::markTestSkipped('Brak ELASTICSEARCH_HOST — uruchom w kontenerze search-consumer.');
        }

        $client = (new ElasticsearchClientFactory(
            $host,
            $this->env('ELASTICSEARCH_USER'),
            $this->env('ELASTICSEARCH_PASSWORD'),
        ))->create();

        try {
            $client->info();
        } catch (\Throwable $e) {
            self::markTestSkipped("Elasticsearch nieosiągalny ({$host}): {$e->getMessage()}");
        }

        if (! is_dir(self::MAPPINGS_DIR)) {
            self::markTestSkipped('Brak katalogu mapowań '.self::MAPPINGS_DIR.' (montowany w kontenerze search-consumer).');
        }

        return $client;
    }

    /** @return array<string, mixed> treść products-vN.json bez `_meta` — gotowa do `indices()->create`. */
    private function mappingBody(int $version): array
    {
        $body = json_decode(file_get_contents(self::MAPPINGS_DIR."/products-v{$version}.json"), associative: true, flags: \JSON_THROW_ON_ERROR);
        unset($body['_meta']);

        return $body;
    }

    /** Dokumenty o ID from..to z wersją zewnętrzną `baseVersion + id`, widoczne od razu (refresh). */
    private function bulkProducts(Client $client, string $index, int $from, int $to, int $baseVersion = 1_000_000): void
    {
        foreach (array_chunk(range($from, $to), 1000) as $chunk) {
            $body = [];
            foreach ($chunk as $id) {
                $body[] = ['index' => ['_index' => $index, '_id' => (string) $id, 'version' => $baseVersion + $id, 'version_type' => 'external']];
                $body[] = $this->product($id);
            }
            $response = $client->bulk(['body' => $body, 'refresh' => 'true'])->asArray();
            if ($response['errors']) {
                throw new \RuntimeException('bulk z błędami: '.json_encode(array_slice($response['items'], 0, 2)));
            }
        }
    }

    /** @return array<string, mixed> `_source` w kształcie projekcji catalogu */
    private function product(int $id, string $name = ''): array
    {
        return [
            'name' => $name !== '' ? $name : "Produkt {$id}",
            'description' => null,
            'brand' => null,
            'category' => null,
            'attributes' => [],
            'price_min' => 1999,
            'price_max' => 1999,
            'in_stock' => true,
            'rating_avg' => null,
            'rating_count' => 0,
            'offers' => [],
            'created_at' => '2026-11-19T12:00:00+00:00',
            'indexed_at' => '2026-11-19T12:00:00+00:00',
        ];
    }

    private function countDocs(Client $client, string $indexOrAlias): int
    {
        return (int) $client->count(['index' => $indexOrAlias])->asArray()['count'];
    }

    /** Catalog, który na `GET /api/internal/products/count` odpowiada `$count()`. */
    private function fakeCatalog(\Closure $count): CatalogProjectionClient
    {
        return new CatalogProjectionClient('http://catalog.invalid', new TraceContext, new MockHttpClient(
            static function (string $method, string $url) use ($count): MockResponse {
                if (! str_ends_with(parse_url($url, \PHP_URL_PATH), '/api/internal/products/count')) {
                    return new MockResponse('', ['http_code' => 404]);
                }

                return new MockResponse(json_encode(['count' => $count()]), ['response_headers' => ['content-type: application/json']]);
            },
        ));
    }

    private function env(string $name): string
    {
        return (string) ($_SERVER[$name] ?? getenv($name) ?: '');
    }
}
