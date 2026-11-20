<?php

namespace App\Service;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;

/**
 * Bramka jakości przed przełączeniem odczytu (ETAP 10, spec 5.5) — część
 * liczona w search-service:
 *  1. indeks zielony, po `_refresh`;
 *  2. |liczba dokumentów − liczba produktów w catalogu| / catalog ≤ tolerancja.
 * nDCG (reguła 3) porównuje skrypt, bo `search:eval` żyje w catalogu.
 *
 * DLACZEGO tolerancja, a nie równość: catalog liczy wiersze teraz, a strumień
 * zdarzeń dociera do ES z opóźnieniem — kilka produktów "w locie" to nie błąd
 * reindeksu. Domyślne 0,001 (300 przy 300 tys.) jest o rzędy wielkości
 * mniejsze niż skutek przerwanego `_reindex` (dziesiątki procent), więc
 * bramka dalej łapie to, po co istnieje.
 */
final class IndexGate
{
    public const DEFAULT_TOLERANCE = 0.001;

    public function __construct(
        private readonly Client $client,
        private readonly CatalogProjectionClient $catalog,
        private readonly string $healthTimeout = '60s',
    ) {
    }

    public function check(string $index, float $tolerance = self::DEFAULT_TOLERANCE): GateResult
    {
        $failures = [];

        $this->client->indices()->refresh(['index' => $index]);

        $health = $this->waitForGreen($index);
        if ($health !== 'green') {
            $failures[] = "zdrowie: {$index} jest {$health}, wymagany green (czekano {$this->healthTimeout})";
        }

        $count = (int) $this->client->count(['index' => $index])->asArray()['count'];

        try {
            $catalog = $this->catalog->countProducts();
        } catch (ProjectionUnavailableException $e) {
            // Bez źródła prawdy nie da się nic potwierdzić — odmowa, nie zgadywanie.
            $catalog = null;
            $failures[] = 'liczba dokumentów: catalog niedostępny ('.$e->getMessage().')';
        }

        if ($catalog !== null) {
            $diff = abs($count - $catalog);
            $ratio = $catalog === 0 ? ($diff === 0 ? 0.0 : \INF) : $diff / $catalog;
            if ($ratio > $tolerance) {
                $failures[] = sprintf(
                    'liczba dokumentów: %s ma %d, catalog %d (różnica %d = %.4f > tolerancja %s)',
                    $index, $count, $catalog, $diff, $ratio, $tolerance,
                );
            }
        }

        return new GateResult($index, $health, $count, $catalog, $tolerance, $failures);
    }

    /** Czeka na zielony do `healthTimeout`; zwraca zdrowie indeksu (green/yellow/red). */
    public function waitForGreen(string $index): string
    {
        try {
            $health = $this->client->cluster()->health([
                'index' => $index,
                'wait_for_status' => 'green',
                'timeout' => $this->healthTimeout,
            ])->asArray();
        } catch (ClientResponseException $e) {
            // 408 = minął timeout bez zielonego; ciało nadal niesie status.
            if ($e->getCode() !== 408) {
                throw $e;
            }
            $health = json_decode((string) $e->getResponse()->getBody(), true) ?? [];
        }

        return (string) ($health['status'] ?? 'unknown');
    }
}
