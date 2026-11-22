<?php

namespace App\Seller360;

use Psr\Log\LoggerInterface;

/**
 * Liczniki projektora w Redisie (`INCR`); odczytuje je Task 10 (metryki seller-360).
 * Klucze mają postać gotowych serii Prometheusa:
 *   seller360_section_writes_total{section="profile",result="created"}
 *   seller360_offer_events_total
 *   seller360_region_unknown_total
 *
 * Metryka nigdy nie psuje przetwarzania zdarzenia: błąd Redisa kończy się ostrzeżeniem w logu.
 */
class Seller360Metrics
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sectionWrite(string $section, string $result): void
    {
        $this->incr(\sprintf('seller360_section_writes_total{section="%s",result="%s"}', $section, $result));
    }

    public function offerEvent(): void
    {
        $this->incr('seller360_offer_events_total');
    }

    public function regionUnknown(): void
    {
        $this->incr('seller360_region_unknown_total');
    }

    private function incr(string $key): void
    {
        try {
            $this->redis->incr($key);
        } catch (\Throwable $e) {
            $this->logger->warning('Nie udało się zwiększyć licznika seller-360.', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }
}
