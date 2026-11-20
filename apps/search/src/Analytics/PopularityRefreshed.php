<?php

namespace App\Analytics;

/** Wynik `PopularityRefresher::refresh()` — dane do podsumowania komendy. */
final class PopularityRefreshed
{
    /**
     * @param array<int, array{impressions:int, clicks:int}> $counts     liczniki pozycji 1..20 w oknie
     * @param array<int, float>                              $propensity p(k) po korekcie izotonicznej
     * @param array<string, mixed>                           $ubq        odpowiedź `_update_by_query` (updated, version_conflicts…)
     * @param ?array<string, mixed>                          $ubqError   `error` zadania, jeśli padło
     * @param array<string, float>                           $timings    krok => sekundy
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly array $counts,
        public readonly array $propensity,
        public readonly float $meanCtr,
        public readonly float $meanCtrIps,
        public readonly int $products,
        public readonly array $ubq,
        public readonly bool $cancelled,
        public readonly ?array $ubqError,
        public readonly array $timings,
    ) {
    }
}
