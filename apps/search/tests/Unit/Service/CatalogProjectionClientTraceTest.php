<?php

namespace App\Tests\Unit\Service;

use App\Service\CatalogProjectionClient;
use App\Tracing\TraceContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CatalogProjectionClientTraceTest extends TestCase
{
    private const TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';

    /** @return array<int, string> nagłówki żądania */
    private function capture(TraceContext $trace): array
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = $options['headers'];

            return new MockResponse(json_encode(['projected_at_us' => 1]), ['response_headers' => ['content-type: application/json']]);
        });

        (new CatalogProjectionClient('http://catalog', $trace, $http))->fetchProductProjection('7');

        return $captured;
    }

    public function test_wysyla_traceparent_gdy_jest_slad(): void
    {
        $trace = new TraceContext;
        $trace->set(self::TRACE);

        $this->assertMatchesRegularExpression(
            '/traceparent: 00-'.self::TRACE.'-[0-9a-f]{16}-01$/m',
            implode("\n", $this->capture($trace)),
        );
    }

    public function test_bez_sladu_brak_naglowka(): void
    {
        $this->assertStringNotContainsString('traceparent', implode("\n", $this->capture(new TraceContext)));
    }
}
