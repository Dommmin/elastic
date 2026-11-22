<?php

namespace App\Tests\Unit\Seller360;

use App\Seller360\SectionMeta;
use App\Seller360\SectionWriter;
use App\Seller360\Seller360Metrics;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * Kształt żądań writera (bez ES): `require_alias` przy zapisie do aliasu zapisu (`*-write`), bez niego przy
 * zapisie do konkretnego indeksu `-vN` (podwójny zapis przebudowy). Alias, którego nie ma, daje wtedy 404
 * `index_not_found_exception` zamiast auto-utworzenia zwykłego indeksu o nazwie aliasu (RUNBOOK #150).
 */
final class SectionWriterRequestTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    private function client(): Client
    {
        $http = new class($this->requests) implements ClientInterface {
            /** @param list<RequestInterface> $requests */
            public function __construct(private array &$requests)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                if (str_contains($request->getUri()->getPath(), '_bulk')) {
                    $lines = array_values(array_filter(explode("\n", (string) $request->getBody())));
                    $items = [];
                    for ($i = 0; $i < \count($lines); $i += 2) {
                        $items[] = ['update' => ['status' => 200, 'result' => 'updated']];
                    }
                    $body = json_encode(['errors' => false, 'items' => $items]);
                } else {
                    $body = json_encode(['result' => 'updated']);
                }

                return new Response(200, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], $body);
            }
        };

        return ClientBuilder::create()->setHosts(['http://es.invalid:9200'])->setHttpClient($http)->build();
    }

    private function writer(): SectionWriter
    {
        return new SectionWriter($this->client(), new Seller360Metrics($this->createStub(\Redis::class), new NullLogger));
    }

    private function meta(): SectionMeta
    {
        return new SectionMeta(3, new \DateTimeImmutable('2026-11-21T10:00:00+00:00'));
    }

    /** @return array<string, string> */
    private function query(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);

        return $query;
    }

    public function test_pojedynczy_zapis_do_aliasu_wymaga_aliasu_a_do_indeksu_vn_nie(): void
    {
        $this->writer()->forPairs([
            ['main' => 'seller-360-write', 'public' => 'seller-360-public-write'],
            ['main' => 'seller-360-v3', 'public' => 'seller-360-public-v3'],
        ])->apply(7, 'profile', ['name' => 'Sklep'], $this->meta());

        $byIndex = [];
        foreach ($this->requests as $request) {
            $byIndex[explode('/', trim($request->getUri()->getPath(), '/'))[0]] = $this->query($request);
        }

        self::assertSame('true', $byIndex['seller-360-write']['require_alias'] ?? null);
        self::assertSame('true', $byIndex['seller-360-public-write']['require_alias'] ?? null);
        self::assertArrayNotHasKey('require_alias', $byIndex['seller-360-v3']);
        self::assertArrayNotHasKey('require_alias', $byIndex['seller-360-public-v3']);
    }

    public function test_bulk_ustawia_require_alias_w_metadanych_akcji_tylko_dla_aliasow(): void
    {
        $this->writer()->forPairs([
            ['main' => 'seller-360-write', 'public' => 'seller-360-public-write'],
            ['main' => 'seller-360-v3', 'public' => 'seller-360-public-v3'],
        ])->applyMany([['sellerId' => 7, 'section' => 'offers', 'data' => ['active' => 1], 'meta' => $this->meta()]]);

        self::assertCount(1, $this->requests);
        $lines = array_values(array_filter(explode("\n", (string) $this->requests[0]->getBody())));
        $actions = [];
        for ($i = 0; $i < \count($lines); $i += 2) {
            $update = json_decode($lines[$i], associative: true)['update'];
            $actions[$update['_index']] = $update;
        }

        self::assertTrue($actions['seller-360-write']['require_alias'] ?? null);
        self::assertTrue($actions['seller-360-public-write']['require_alias'] ?? null);
        self::assertArrayNotHasKey('require_alias', $actions['seller-360-v3']);
        self::assertArrayNotHasKey('require_alias', $actions['seller-360-public-v3']);
    }
}
