<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /metrics` — jedyny endpoint HTTP w search; scrapuje go Prometheus
 * z osobnego kontenera `search-metrics` (search-consumer to worker bez HTTP).
 *
 * DLACZEGO format tekstowy składamy ręcznie: to jedna metryka (gauge), a
 * biblioteka promphp dojdzie dopiero w następnym PR — wtedy ten kontroler
 * zniknie na rzecz jej renderera. Nie ciągniemy zależności dla jednej linii.
 *
 * DLACZEGO błąd odczytu to 200 + `search_metrics_scrape_error 1`, a nie 500:
 * Prometheus i tak dostaje sygnał (alert na tę metrykę), a wykres nie ma
 * dziury z powodu `up == 0`, która maskowałaby prawdziwą przyczynę.
 */
final class MetricsController
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.failed')]
        private readonly MessageCountAwareInterface $failedTransport,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/metrics', methods: ['GET'])]
    public function __invoke(): Response
    {
        try {
            $count = $this->failedTransport->getMessageCount();
            $body = "# HELP search_messenger_failed_messages Wiadomości w failure transport (messenger:failed:show).\n"
                ."# TYPE search_messenger_failed_messages gauge\n"
                ."search_messenger_failed_messages {$count}\n";
        } catch (\Throwable $e) {
            $this->logger->warning('Nie udało się odczytać failure transportu dla /metrics', ['exception' => $e]);
            $body = "# HELP search_metrics_scrape_error 1, gdy odczyt metryk się nie powiódł.\n"
                ."# TYPE search_metrics_scrape_error gauge\n"
                ."search_metrics_scrape_error 1\n";
        }

        // Wersja formatu w nagłówku jest wymagana przez Prometheus; charset
        // wpisujemy jawnie, bo Symfony i tak dopisałby go przy wysyłce.
        return new Response($body, 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=UTF-8']);
    }
}
