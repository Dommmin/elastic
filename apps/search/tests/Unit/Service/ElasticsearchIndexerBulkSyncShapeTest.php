<?php

namespace App\Tests\Unit\Service;

use App\Service\ElasticsearchIndexer;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Odpowiedź `_bulk` musi mieć tyle pozycji, ile wysłaliśmy akcji. Krótsza
 * (ucięta, zła wersja ES, proxy) bez sprawdzenia dałaby zaniżone liczniki
 * i 0 błędów — backfill postawiłby pieczęć mimo niezapisanych dokumentów.
 */
final class ElasticsearchIndexerBulkSyncShapeTest extends TestCase
{
    public function test_odpowiedz_z_inna_liczba_pozycji_to_wyjatek(): void
    {
        $indexer = new ElasticsearchIndexer($this->esReturning(['took' => 1, 'errors' => false, 'items' => [
            ['index' => ['_id' => '1', 'status' => 201]],
        ]]), 'products-x');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('2 akcji');
        $indexer->bulkSync([['id' => 1, 'name' => 'A']], [2], 1_790_000_000_000_000);
    }

    public function test_odpowiedz_bez_items_to_wyjatek(): void
    {
        $indexer = new ElasticsearchIndexer($this->esReturning(['took' => 1, 'errors' => false]), 'products-x');

        $this->expectException(\UnexpectedValueException::class);
        $indexer->bulkSync([['id' => 1, 'name' => 'A']], [], 1_790_000_000_000_000);
    }

    /** @param array<string, mixed> $body */
    private function esReturning(array $body): \Elastic\Elasticsearch\Client
    {
        $http = new class($body) implements ClientInterface {
            /** @param array<string, mixed> $body */
            public function __construct(private readonly array $body)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode($this->body));
            }
        };

        return ClientBuilder::create()->setHosts(['http://es.invalid:9200'])->setHttpClient($http)->build();
    }
}
