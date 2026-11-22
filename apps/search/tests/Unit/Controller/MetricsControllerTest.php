<?php

namespace App\Tests\Unit\Controller;

use App\Controller\MetricsController;
use App\Seller360\Registry\CircuitBreaker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

final class MetricsControllerTest extends TestCase
{
    public function testExposesFailedMessagesGauge(): void
    {
        $transport = $this->createStub(MessageCountAwareInterface::class);
        $transport->method('getMessageCount')->willReturn(3);

        $response = (new MetricsController($transport, $this->createStub(LoggerInterface::class)))();
        // prepare() robi to samo co kernel przed wysyłką — asertujemy nagłówek
        // taki, jaki naprawdę trafi do Prometheusa (z dopisanym charsetem).
        $response->prepare(Request::create('/metrics'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; version=0.0.4; charset=UTF-8', $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        self::assertStringContainsString('# TYPE search_messenger_failed_messages gauge', $body);
        self::assertStringContainsString("\nsearch_messenger_failed_messages 3\n", $body);
        self::assertStringNotContainsString('search_metrics_scrape_error', $body);
    }

    public function testReportsScrapeErrorInsteadOfFailingWhenTransportThrows(): void
    {
        $transport = $this->createStub(MessageCountAwareInterface::class);
        $transport->method('getMessageCount')->willThrowException(new \RuntimeException('db down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $response = (new MetricsController($transport, $logger))();

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString("search_metrics_scrape_error 1\n", $body);
        self::assertStringNotContainsString('search_messenger_failed_messages ', $body);
    }

    public function testExposesSeller360MetricsFromRedis(): void
    {
        $transport = $this->createStub(MessageCountAwareInterface::class);
        $transport->method('getMessageCount')->willReturn(0);
        $lag = ['billing' => ['p50' => 3.0, 'p99' => 12.0, 'max' => 15.5, 'age_max' => 40.0, 'docs' => 9]];
        $redis = $this->createStub(\Redis::class);
        $redis->method('get')->willReturnCallback(static fn (string $key) => match ($key) {
            'seller360:lag' => json_encode($lag),
            'seller360_offer_events_total' => '41',
            default => false,
        });
        $redis->method('mGet')->willReturnCallback(static fn (array $keys): array => array_map(
            static fn (string $k) => $k === 'seller360_section_writes_total{section="profile",result="created"}' ? '5' : false,
            $keys,
        ));
        $breaker = $this->createStub(CircuitBreaker::class);
        $breaker->method('isOpen')->willReturn(true);

        $body = (string) (new MetricsController($transport, $this->createStub(LoggerInterface::class), $redis, $breaker))()->getContent();

        self::assertStringContainsString("\nseller360_section_lag_seconds{section=\"billing\",quantile=\"0.99\"} 12\n", $body);
        self::assertStringContainsString("\nseller360_section_lag_seconds{section=\"billing\",quantile=\"0.5\"} 3\n", $body);
        self::assertStringContainsString("\nseller360_section_lag_seconds{section=\"billing\",quantile=\"max\"} 15.5\n", $body);
        self::assertStringContainsString("\nseller360_section_age_seconds{section=\"billing\"} 40\n", $body);
        self::assertStringContainsString("\nseller360_section_writes_total{section=\"profile\",result=\"created\"} 5\n", $body);
        self::assertStringNotContainsString('result="noop"', $body);
        self::assertStringContainsString("\nseller360_offer_events_total 41\n", $body);
        self::assertStringContainsString("\nseller360_region_unknown_total 0\n", $body);
        self::assertStringContainsString("\nseller360_registry_breaker_open 1\n", $body);
        self::assertStringContainsString("search_messenger_failed_messages 0\n", $body);
    }

    public function testSeller360FailureDoesNotBreakExistingMetrics(): void
    {
        $transport = $this->createStub(MessageCountAwareInterface::class);
        $transport->method('getMessageCount')->willReturn(2);
        $redis = $this->createStub(\Redis::class);
        $redis->method('get')->willThrowException(new \RedisException('down'));
        $redis->method('mGet')->willThrowException(new \RedisException('down'));

        $body = (string) (new MetricsController($transport, $this->createStub(LoggerInterface::class), $redis, $this->createStub(CircuitBreaker::class)))()->getContent();

        self::assertStringContainsString("search_messenger_failed_messages 2\n", $body);
        self::assertStringContainsString("seller360_metrics_scrape_error 1\n", $body);
        self::assertStringNotContainsString('seller360_section_lag_seconds', $body);
    }
}
