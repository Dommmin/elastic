<?php

namespace App\Tests\Unit\Service;

use App\Service\CatalogProjectionClient;
use App\Service\ProjectionUnavailableException;
use App\Tracing\TraceContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Wsadowe projekcje i ID zmienione od chwili (ETAP 10, Task 12b: praktyczna droga B i ograniczony abort). */
final class CatalogProjectionClientBatchTest extends TestCase
{
    public function test_fetch_projections_wysyla_ids_po_przecinku_i_zwraca_partie(): void
    {
        $url = null;
        $body = ['projected_at_us' => 1_790_000_000_000_000, 'items' => [['id' => 1, 'projected_at_us' => 1_790_000_000_000_000, 'name' => 'A']], 'missing' => [2]];
        $client = $this->client(function (string $method, string $u) use (&$url, $body) {
            $url = $u;

            return $this->json($body);
        });

        self::assertSame($body, $client->fetchProjections([1, 2]));
        self::assertSame('http://catalog/api/internal/products/projections?ids=1%2C2', $url);
    }

    public function test_fetch_projections_pustej_listy_nie_pyta_catalogu(): void
    {
        $client = $this->client(fn () => self::fail('Pusta lista nie powinna wołać catalogu.'));

        self::assertSame(['projected_at_us' => 0, 'items' => [], 'missing' => []], $client->fetchProjections([]));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function zleOdpowiedziPartii(): iterable
    {
        yield 'bez projected_at_us' => [['items' => [], 'missing' => []]];
        yield 'items nie lista' => [['projected_at_us' => 1, 'items' => 'x', 'missing' => []]];
        yield 'missing z napisem' => [['projected_at_us' => 1, 'items' => [], 'missing' => ['2']]];
        yield 'item bez id' => [['projected_at_us' => 1, 'items' => [['name' => 'A']], 'missing' => []]];
    }

    /** @param array<string, mixed> $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('zleOdpowiedziPartii')]
    public function test_fetch_projections_w_zlym_ksztalcie_to_blad(array $body): void
    {
        $this->expectException(ProjectionUnavailableException::class);
        $this->client(fn () => $this->json($body))->fetchProjections([1]);
    }

    public function test_fetch_projections_przy_5xx_to_blad(): void
    {
        $this->expectException(ProjectionUnavailableException::class);
        $this->client(fn () => new MockResponse('', ['http_code' => 503]))->fetchProjections([1]);
    }

    public function test_changed_product_ids_przekazuje_since_kursor_i_limit(): void
    {
        $url = null;
        $client = $this->client(function (string $method, string $u) use (&$url) {
            $url = $u;

            return $this->json(['ids' => [11, 12], 'next_after' => 12]);
        });

        self::assertSame(['ids' => [11, 12], 'next_after' => 12], $client->changedProductIds('2026-11-20T00:40:06+00:00', 10, 2));
        self::assertSame('http://catalog/api/internal/products/changed?since=2026-11-20T00%3A40%3A06%2B00%3A00&after=10&limit=2', $url);
    }

    public function test_changed_product_ids_bez_kursora(): void
    {
        $url = null;
        $client = $this->client(function (string $method, string $u) use (&$url) {
            $url = $u;

            return $this->json(['ids' => [], 'next_after' => null]);
        });

        self::assertSame(['ids' => [], 'next_after' => null], $client->changedProductIds('2026-11-20T00:00:00Z', null, 500));
        self::assertSame('http://catalog/api/internal/products/changed?since=2026-11-20T00%3A00%3A00Z&limit=500', $url);
    }

    public function test_changed_product_ids_w_zlym_ksztalcie_to_blad(): void
    {
        $this->expectException(ProjectionUnavailableException::class);
        $this->client(fn () => $this->json(['ids' => [1], 'next_after' => 'x']))->changedProductIds('2026-11-20T00:00:00Z', null, 10);
    }

    private function client(\Closure $responder): CatalogProjectionClient
    {
        return new CatalogProjectionClient('http://catalog/', new TraceContext, new MockHttpClient($responder));
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body), ['response_headers' => ['content-type: application/json']]);
    }
}
