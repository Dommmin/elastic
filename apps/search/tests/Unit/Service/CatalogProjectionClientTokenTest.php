<?php

namespace App\Tests\Unit\Service;

use App\Service\CatalogProjectionClient;
use App\Tracing\TraceContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Każde żądanie do /api/internal/* niesie wspólny sekret w X-Internal-Token
 * (catalog: RequireInternalToken). Sprawdzamy oba typy żądań: pojedynczą
 * projekcję (consumer) i ścieżki wsadowe narzędzi operatorskich.
 */
final class CatalogProjectionClientTokenTest extends TestCase
{
    /** @var list<string> */
    private array $tokens = [];

    private function client(string $token): CatalogProjectionClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->tokens[] = $options['normalized_headers']['x-internal-token'][0] ?? '(brak)';

            $body = str_contains($url, '/count') ? ['count' => 3] : ['projected_at_us' => 1];

            return new MockResponse(json_encode($body), ['response_headers' => ['content-type: application/json']]);
        });

        return new CatalogProjectionClient('http://catalog', new TraceContext, $http, $token);
    }

    public function test_pojedyncza_projekcja_wysyla_token(): void
    {
        $this->client('s3cret')->fetchProductProjection('7');

        $this->assertSame(['X-Internal-Token: s3cret'], $this->tokens);
    }

    public function test_zapytania_wsadowe_wysylaja_token(): void
    {
        $this->assertSame(3, $this->client('s3cret')->countProducts());

        $this->assertSame(['X-Internal-Token: s3cret'], $this->tokens);
    }

    public function test_pusty_token_nie_wysyla_naglowka(): void
    {
        $this->client('')->fetchProductProjection('7');

        $this->assertSame(['(brak)'], $this->tokens);
    }
}
