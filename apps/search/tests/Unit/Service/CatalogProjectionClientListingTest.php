<?php

namespace App\Tests\Unit\Service;

use App\Service\CatalogProjectionClient;
use App\Service\ProjectionUnavailableException;
use App\Tracing\TraceContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Licznik i stronicowane ID produktów z catalogu (ETAP 10: bramka i droga B). */
final class CatalogProjectionClientListingTest extends TestCase
{
    public function test_count_products(): void
    {
        $url = null;
        $client = $this->client(function (string $method, string $u) use (&$url) {
            $url = $u;

            return $this->json(['count' => 300000]);
        });

        self::assertSame(300000, $client->countProducts());
        self::assertSame('http://catalog/api/internal/products/count', $url);
    }

    public function test_count_bez_liczby_calkowitej_to_blad(): void
    {
        $this->expectException(ProjectionUnavailableException::class);
        $this->client(fn () => $this->json(['count' => '12']))->countProducts();
    }

    public function test_count_przy_5xx_to_blad(): void
    {
        $this->expectException(ProjectionUnavailableException::class);
        $this->client(fn () => new MockResponse('', ['http_code' => 503]))->countProducts();
    }

    public function test_product_ids_przekazuje_kursor_i_limit(): void
    {
        $url = null;
        $client = $this->client(function (string $method, string $u) use (&$url) {
            $url = $u;

            return $this->json(['ids' => [11, 12], 'next_after' => 12]);
        });

        self::assertSame(['ids' => [11, 12], 'next_after' => 12], $client->productIds(10, 2));
        self::assertSame('http://catalog/api/internal/products/ids?after=10&limit=2', $url);
    }

    public function test_product_ids_pierwsza_strona_bez_after_i_koniec_listy(): void
    {
        $url = null;
        $client = $this->client(function (string $method, string $u) use (&$url) {
            $url = $u;

            return $this->json(['ids' => [], 'next_after' => null]);
        });

        self::assertSame(['ids' => [], 'next_after' => null], $client->productIds(null, 500));
        self::assertSame('http://catalog/api/internal/products/ids?limit=500', $url);
    }

    public function test_product_ids_z_blednym_ksztaltem_to_blad(): void
    {
        $this->expectException(ProjectionUnavailableException::class);
        $this->client(fn () => $this->json(['ids' => ['x'], 'next_after' => null]))->productIds(null, 10);
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
