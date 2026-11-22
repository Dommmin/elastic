<?php

namespace App\Seller360;

use Elastic\Elasticsearch\Client;
use Psr\Log\LoggerInterface;

/**
 * Świeżość sekcji `seller-360` (ETAP 13, Task 10): lag per sekcja, liczony co 30 s przez scheduler
 * i odkładany w Redisie (`seller360:lag`, JSON), skąd czyta go `/metrics`.
 *
 * DLACZEGO lag to `_meta.updated_at − _meta.source_ts`, a nie `now − source_ts`:
 * sekcje sterowane zdarzeniami (profile, billing) bywają bezczynne całymi dniami, bo nikt nic nie zmienił
 * u źródła. Przy `now − source_ts` taka sekcja wyglądałaby na przeterminowaną i alert „p99 > 300 s"
 * odpalałby się bez końca. Różnica `updated_at − source_ts` mierzy to, na co projektor ma wpływ:
 * ile minęło od zdarzenia u źródła do zapisu w read modelu. Rejestr (zapisy `stale`) zachowuje stary
 * `source_ts` przy świeżym `updated_at`, więc jego lag rośnie w trakcie awarii rejestru, zgodnie z intencją.
 *
 * Różnica liczona jest w ES polem runtime (Painless) zdefiniowanym w samym żądaniu, więc nie wymaga zmiany
 * mapowania; agregacje `percentiles` (50, 99) i `max` obejmują dokumenty, które mają daną sekcję.
 *
 * Drugi wskaźnik, `age_max`, to `now − max(<sekcja>._meta.updated_at)` w sekundach. Przy bezczynnej,
 * ale zdrowej sekcji jest duży i nie jest to błąd; ma wyłapać martwego pisarza (np. `offers` nie jest
 * zapisywana od godziny mimo ruchu), czego sam lag nie pokaże, bo nie przybywa próbek.
 */
class LagReport implements Seller360Job
{
    public const SECTIONS = ['profile', 'offers', 'sales', 'billing', 'complaints', 'registry'];

    public const REDIS_KEY = 'seller360:lag';

    /** Gdy scheduler stanie, klucz wygasa i metryki lagu znikają (zamiast pokazywać stare wartości). */
    private const TTL_SECONDS = 120;

    public function __construct(
        private readonly Client $client,
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
        private readonly string $index = 'seller-360',
    ) {
    }

    public function name(): string
    {
        return 'lag';
    }

    public function intervalSeconds(): int
    {
        return 30;
    }

    public function run(): void
    {
        $this->refresh(new \DateTimeImmutable);
    }

    /**
     * Liczy raport i zapisuje go w Redisie.
     *
     * @return array<string, array{p50: float, p99: float, max: float, age_max: float, docs: int}>
     */
    public function refresh(\DateTimeImmutable $now): array
    {
        $report = $this->compute($now);
        try {
            $this->redis->set(self::REDIS_KEY, json_encode($report, \JSON_THROW_ON_ERROR), ['EX' => self::TTL_SECONDS]);
        } catch (\Throwable $e) {
            $this->logger->warning('Nie udało się zapisać raportu lagu seller-360 w Redisie.', ['error' => $e->getMessage()]);
        }

        return $report;
    }

    /**
     * @return array<string, array{p50: float, p99: float, max: float, age_max: float, docs: int}>
     */
    public function compute(\DateTimeImmutable $now): array
    {
        $runtime = [];
        $aggs = [];
        foreach (self::SECTIONS as $s) {
            $runtime["{$s}_lag"] = [
                'type' => 'double',
                'script' => ['source' => \sprintf(
                    "if (doc['%1\$s._meta.updated_at'].size() == 0 || doc['%1\$s._meta.source_ts'].size() == 0) { return; } "
                    ."emit((doc['%1\$s._meta.updated_at'].value.toInstant().toEpochMilli() - doc['%1\$s._meta.source_ts'].value.toInstant().toEpochMilli()) / 1000.0);",
                    $s,
                )],
            ];
            $aggs[$s] = [
                'filter' => ['exists' => ['field' => "{$s}._meta.updated_at"]],
                'aggs' => [
                    'lag' => ['percentiles' => ['field' => "{$s}_lag", 'percents' => [50, 99]]],
                    'lag_max' => ['max' => ['field' => "{$s}_lag"]],
                    'last_update' => ['max' => ['field' => "{$s}._meta.updated_at"]],
                ],
            ];
        }

        $response = $this->client->search([
            'index' => $this->index,
            'body' => ['size' => 0, 'runtime_mappings' => $runtime, 'aggs' => $aggs],
        ])->asArray();

        $nowSeconds = (float) $now->format('U.u');
        $report = [];
        foreach (self::SECTIONS as $s) {
            $agg = $response['aggregations'][$s] ?? [];
            $values = $agg['lag']['values'] ?? [];
            $lastUpdateMs = $agg['last_update']['value'] ?? null;
            $report[$s] = [
                'p50' => (float) ($values['50.0'] ?? 0),
                'p99' => (float) ($values['99.0'] ?? 0),
                'max' => (float) ($agg['lag_max']['value'] ?? 0),
                'age_max' => $lastUpdateMs === null ? 0.0 : max(0.0, $nowSeconds - $lastUpdateMs / 1000),
                'docs' => (int) ($agg['doc_count'] ?? 0),
            ];
        }

        return $report;
    }
}
