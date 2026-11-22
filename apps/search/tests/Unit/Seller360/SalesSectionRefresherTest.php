<?php

namespace App\Tests\Unit\Seller360;

use App\Seller360\SalesSectionRefresher;
use App\Seller360\SectionWriter;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SalesSectionRefresherTest extends TestCase
{
    /** @param list<array{int, int, int, int, int, int}> $rows seller, cur_carts, cur_orders, gmv, prev_carts, prev_orders */
    private function es(array $rows): Client
    {
        $buckets = array_map(static fn (array $r): array => [
            'key' => ['seller_id' => (string) $r[0]],
            'cur' => ['cart' => ['value' => (float) $r[1]], 'orders' => ['value' => (float) $r[2]], 'gmv' => ['value' => (float) $r[3]]],
            'prev' => ['cart' => ['value' => (float) $r[4]], 'orders' => ['value' => (float) $r[5]]],
        ], $rows);
        $body = json_encode(['aggregations' => ['sellers' => ['buckets' => $buckets]]]);
        $http = new class($body) implements ClientInterface {
            public function __construct(private readonly string $body)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], $this->body);
            }
        };

        return ClientBuilder::create()->setHosts(['http://es.invalid:9200'])->setHttpClient($http)->build();
    }

    /** @return array<string, mixed> data pierwszej (jedynej) pozycji przekazanej writerowi */
    private function refreshOne(array $row): array
    {
        $captured = [];
        $writer = $this->createStub(SectionWriter::class);
        $writer->method('applyMany')->willReturnCallback(function (array $items) use (&$captured): array {
            $captured = $items;

            return array_fill(0, \count($items), 'updated');
        });
        $now = new \DateTimeImmutable('2026-11-21T12:00:00+00:00');
        (new SalesSectionRefresher($this->es([$row]), $writer, new NullLogger))->refresh($now);

        self::assertCount(1, $captured);
        self::assertSame('sales', $captured[0]['section']);
        self::assertSame($now->getTimestamp() * 1000, $captured[0]['meta']->version);

        return $captured[0]['data'];
    }

    public function test_prev_19_sesji_koszyka_daje_conversion_drop_null(): void
    {
        $data = $this->refreshOne([7, 30, 9, 1000, 19, 10]);

        self::assertNull($data['conversion_drop']);
        self::assertSame(19, $data['cart_sessions_prev_30d']);
    }

    public function test_spadek_konwersji_z_05_do_03_to_04(): void
    {
        $data = $this->refreshOne([7, 30, 9, 120000, 40, 20]);

        self::assertEqualsWithDelta(0.3, $data['conversion_30d'], 1e-9);
        self::assertEqualsWithDelta(0.5, $data['conversion_prev_30d'], 1e-9);
        self::assertEqualsWithDelta(0.4, $data['conversion_drop'], 1e-9);
        self::assertSame(120000, $data['gmv_30d_cents']);
        self::assertSame(9, $data['orders_30d']);
        self::assertSame(30, $data['cart_sessions_30d']);
    }

    public function test_zero_sesji_koszyka_daje_konwersje_null(): void
    {
        $data = $this->refreshOne([7, 0, 0, 0, 40, 20]);

        self::assertNull($data['conversion_30d']);
        self::assertNull($data['conversion_drop']);
    }
}
