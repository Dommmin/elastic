<?php

namespace App\Tests\Unit\Seller360;

use App\Seller360\LagReport;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

final class LagReportTest extends TestCase
{
    /** @var list<string> */
    private array $requests = [];

    /** @param array<string, mixed> $aggregations */
    private function es(array $aggregations): Client
    {
        $http = new class(json_encode(['aggregations' => $aggregations]), $this->requests) implements ClientInterface {
            /** @param list<string> $requests */
            public function __construct(private readonly string $body, private array &$requests)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = (string) $request->getBody();

                return new Response(200, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], $this->body);
            }
        };

        return ClientBuilder::create()->setHosts(['http://es.invalid:9200'])->setHttpClient($http)->build();
    }

    public function testComputesLagSecondsAndAgeFromAggregations(): void
    {
        $now = new \DateTimeImmutable('2026-11-22T12:00:00Z');
        $updatedMs = $now->modify('-90 seconds')->getTimestamp() * 1000;
        $es = $this->es([
            'billing' => [
                'doc_count' => 7,
                'lag' => ['values' => ['50.0' => 2.5, '99.0' => 12.0]],
                'lag_max' => ['value' => 20.0],
                'last_update' => ['value' => (float) $updatedMs],
            ],
            // sekcja bez dokumentów: ES zwraca null w agregacjach
            'sales' => [
                'doc_count' => 0,
                'lag' => ['values' => ['50.0' => null, '99.0' => null]],
                'lag_max' => ['value' => null],
                'last_update' => ['value' => null],
            ],
        ]);

        $report = (new LagReport($es, $this->createStub(\Redis::class), new NullLogger))->compute($now);

        self::assertSame(['profile', 'offers', 'sales', 'billing', 'complaints', 'registry'], array_keys($report));
        self::assertSame(['p50' => 2.5, 'p99' => 12.0, 'max' => 20.0, 'age_max' => 90.0, 'docs' => 7], $report['billing']);
        self::assertSame(['p50' => 0.0, 'p99' => 0.0, 'max' => 0.0, 'age_max' => 0.0, 'docs' => 0], $report['sales']);
        self::assertSame(0, $report['profile']['docs']);
    }

    public function testQueryDefinesRuntimeLagFieldPerSection(): void
    {
        $es = $this->es([]);
        (new LagReport($es, $this->createStub(\Redis::class), new NullLogger))->compute(new \DateTimeImmutable);

        $body = json_decode($this->requests[0], true);
        self::assertSame('double', $body['runtime_mappings']['billing_lag']['type']);
        self::assertStringContainsString("doc['billing._meta.updated_at']", $body['runtime_mappings']['billing_lag']['script']['source']);
        self::assertStringContainsString("doc['billing._meta.source_ts']", $body['runtime_mappings']['billing_lag']['script']['source']);
        self::assertSame([50, 99], $body['aggs']['billing']['aggs']['lag']['percentiles']['percents']);
        self::assertSame(0, $body['size']);
    }

    public function testRefreshStoresJsonInRedis(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('set')->with(
            'seller360:lag',
            self::callback(static fn (string $json): bool => json_decode($json, true)['profile']['docs'] === 0),
            self::anything(),
        );

        (new LagReport($this->es([]), $redis, new NullLogger))->refresh(new \DateTimeImmutable);
    }
}
