<?php

namespace App\Tests\Unit\Controller;

use App\Controller\MetricsController;
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
}
