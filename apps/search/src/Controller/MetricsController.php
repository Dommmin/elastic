<?php

namespace App\Controller;

use App\Seller360\LagReport;
use App\Seller360\Registry\CircuitBreaker;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
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
 * Prometheus i tak dostaje sygnał (alert SearchMetricsScrapeError na tę
 * metrykę; bez niego zniknęłaby seria search_messenger_failed_messages, a
 * krytyczny SearchFailedMessages zamilkłby), a wykres nie ma dziury
 * z powodu `up == 0`, która maskowałaby prawdziwą przyczynę.
 *
 * ETAP 13: dopisujemy metryki seller-360 (lag i wiek sekcji z raportu `seller360:lag`, liczniki
 * projektora, stan bezpiecznika rejestru), wszystkie z Redisa. Ich błąd nie ruszy metryk wyżej:
 * kończy się ostrzeżeniem w logu i `seller360_metrics_scrape_error 1`.
 * Redis i bezpiecznik wstrzykujemy jako domknięcia (leniwie): fabryka Redisa łączy się i loguje przy
 * tworzeniu usługi, więc przy niedostępnym Redisie sam kontroler nie dałby się zbudować (500).
 * Domknięcia wołamy dopiero w bloku try.
 */
final class MetricsController
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.failed')]
        private readonly MessageCountAwareInterface $failedTransport,
        private readonly LoggerInterface $logger,
        #[AutowireServiceClosure('Redis')]
        private readonly ?\Closure $redisProvider = null,
        #[AutowireServiceClosure(CircuitBreaker::class)]
        private readonly ?\Closure $breakerProvider = null,
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

        if ($this->redisProvider !== null && $this->breakerProvider !== null) {
            $body .= $this->seller360Metrics($this->redisProvider, $this->breakerProvider);
        }

        // Wersja formatu w nagłówku jest wymagana przez Prometheus; charset
        // wpisujemy jawnie, bo Symfony i tak dopisałby go przy wysyłce.
        return new Response($body, 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=UTF-8']);
    }

    private function seller360Metrics(\Closure $redisProvider, \Closure $breakerProvider): string
    {
        try {
            /** @var \Redis $redis */
            $redis = $redisProvider();
            /** @var CircuitBreaker $breaker */
            $breaker = $breakerProvider();
            $lag = json_decode((string) $redis->get(LagReport::REDIS_KEY), true);
            $missing = \is_array($lag) && $lag !== [] ? 0 : 1;
            $out = "# HELP seller360_section_lag_seconds Lag sekcji seller-360: updated_at minus source_ts (p50, p99, max).\n"
                ."# TYPE seller360_section_lag_seconds gauge\n";
            $age = "# HELP seller360_section_age_seconds Sekundy od ostatniego zapisu sekcji seller-360 (wykrywa martwego pisarza).\n"
                ."# TYPE seller360_section_age_seconds gauge\n";
            foreach (\is_array($lag) ? $lag : [] as $section => $r) {
                foreach (['0.5' => 'p50', '0.99' => 'p99', 'max' => 'max'] as $quantile => $field) {
                    $out .= \sprintf("seller360_section_lag_seconds{section=\"%s\",quantile=\"%s\"} %s\n", $section, $quantile, $this->number($r[$field] ?? 0));
                }
                $age .= \sprintf("seller360_section_age_seconds{section=\"%s\"} %s\n", $section, $this->number($r['age_max'] ?? 0));
            }
            $out .= $age
                ."# HELP seller360_lag_report_missing 1, gdy brak raportu lagu w Redisie (scheduler nie działa albo klucz wygasł).\n"
                ."# TYPE seller360_lag_report_missing gauge\n"
                ."seller360_lag_report_missing {$missing}\n";

            $keys = [];
            foreach (LagReport::SECTIONS as $section) {
                foreach (['created', 'updated', 'noop', 'error'] as $result) {
                    $keys[] = \sprintf('seller360_section_writes_total{section="%s",result="%s"}', $section, $result);
                }
            }
            $out .= "# HELP seller360_section_writes_total Zapisy sekcji seller-360 wg wyniku (created, updated, noop, error).\n"
                ."# TYPE seller360_section_writes_total counter\n";
            foreach ($redis->mGet($keys) as $i => $value) {
                if ($value !== false && $value !== null) {
                    $out .= $keys[$i].' '.(int) $value."\n";
                }
            }
            $out .= "# HELP seller360_offer_events_total Zdarzenia ofert przyjęte przez projektor seller-360.\n"
                ."# TYPE seller360_offer_events_total counter\n"
                .'seller360_offer_events_total '.(int) $redis->get('seller360_offer_events_total')."\n"
                ."# HELP seller360_region_unknown_total Sprzedawcy, dla których nie ustalono regionu.\n"
                ."# TYPE seller360_region_unknown_total counter\n"
                .'seller360_region_unknown_total '.(int) $redis->get('seller360_region_unknown_total')."\n"
                ."# HELP seller360_registry_breaker_open 1, gdy bezpiecznik rejestru jest otwarty.\n"
                ."# TYPE seller360_registry_breaker_open gauge\n"
                .'seller360_registry_breaker_open '.($breaker->isOpen() ? 1 : 0)."\n";

            return $out;
        } catch (\Throwable $e) {
            $this->logger->warning('Nie udało się odczytać metryk seller-360 dla /metrics', ['exception' => $e]);

            return "# HELP seller360_metrics_scrape_error 1, gdy odczyt metryk seller-360 się nie powiódł.\n"
                ."# TYPE seller360_metrics_scrape_error gauge\n"
                ."seller360_metrics_scrape_error 1\n";
        }
    }

    private function number(int|float $value): string
    {
        return rtrim(rtrim(\sprintf('%.3F', $value), '0'), '.') ?: '0';
    }
}
