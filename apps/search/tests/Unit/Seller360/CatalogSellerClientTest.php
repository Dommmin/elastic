<?php

namespace App\Tests\Unit\Seller360;

use App\Seller360\CatalogSellerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CatalogSellerClientTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

    private function client(\Closure $respond): CatalogSellerClient
    {
        return new CatalogSellerClient('http://catalog.test/', new MockHttpClient(function (string $method, string $url) use ($respond): MockResponse {
            $this->urls[] = $url;

            return $respond($url);
        }));
    }

    private static function json(mixed $body, int $code = 200): MockResponse
    {
        return new MockResponse(json_encode($body), ['http_code' => $code, 'response_headers' => ['content-type: application/json']]);
    }

    public function test_strona_sprzedawcow_wysyla_kursor_i_limit(): void
    {
        $page = $this->client(fn () => self::json(['items' => [['seller_id' => 3]], 'next_after' => 3]))->sellers(0, 2000);

        self::assertSame(3, $page['next_after']);
        self::assertSame('http://catalog.test/api/internal/sellers?after=0&limit=2000', $this->urls[0]);
    }

    public function test_strona_kont_rozliczeniowych(): void
    {
        $this->client(fn () => self::json(['items' => [], 'next_after' => null]))->billingAccounts(17, 5);

        self::assertSame('http://catalog.test/api/internal/billing/accounts?after=17&limit=5', $this->urls[0]);
    }

    public function test_offer_stats_wielu_sprzedawcow_404_to_null(): void
    {
        $stats = $this->client(function (string $url): MockResponse {
            preg_match('#/sellers/(\d+)/offer-stats#', $url, $m);

            return $m[1] === '9' ? self::json(['message' => 'brak'], 404) : self::json(['seller_id' => (int) $m[1], 'active' => 1]);
        })->offerStatsMany(range(1, 45) + [44 => 9]);

        self::assertCount(45, $this->urls, 'jedno żądanie na sprzedawcę, w paczkach po '.CatalogSellerClient::CONCURRENCY);
        self::assertNull($stats[9]);
        self::assertSame(1, $stats[3]['active']);
    }

    public function test_offer_stats_wielu_inny_blad_leci_wyjatkiem(): void
    {
        $this->expectException(\Throwable::class);

        $this->client(fn () => self::json(['message' => 'awaria'], 500))->offerStatsMany([1]);
    }
}
