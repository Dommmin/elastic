<?php

namespace App\Providers;

use App\Metrics\CatalogGauges;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Spatie\Prometheus\Facades\Prometheus;
use Throwable;

class PrometheusServiceProvider extends ServiceProvider
{
    /**
     * Gauge'e z `CatalogGauges` (nazwy z prefiksem `catalog_` dokłada
     * `prometheus.default_namespace`). Każdy callback liczy wartość SQL-em
     * dopiero przy scrape, a `CatalogGauges` jest brany z kontenera w chwili
     * scrape'u, nie rejestracji (testy podmieniają go dublerem).
     */
    public function register(): void
    {
        $gauges = [
            'outbox_pending' => ['Liczba zdarzeń w outboxie czekających na publikację', 'outboxPending'],
            'outbox_oldest_pending_age_seconds' => ['Wiek najstarszego niepublikowanego zdarzenia w outboxie (s)', 'outboxOldestPendingAgeSeconds'],
            'offers_pending_moderation' => ['Liczba ofert z ceną czekającą na moderację', 'offersPendingModeration'],
            'offers_pending_moderation_oldest_age_seconds' => ['Wiek najstarszej zmiany ceny czekającej na moderację (s)', 'offersPendingModerationOldestAgeSeconds'],
            'sellers_pending_approval' => ['Liczba sprzedawców czekających na akceptację', 'sellersPendingApproval'],
            'sellers_pending_approval_oldest_age_seconds' => ['Wiek najdłużej czekającego sprzedawcy (s)', 'sellersPendingApprovalOldestAgeSeconds'],
            'orders_stuck_placed' => ['Zamówienia w statusie placed dłużej niż próg (saga rezerwacji utknęła)', 'ordersStuckPlaced'],
            'orders_settled_with_active_reservation' => ['Zamówienia opłacone lub wysłane z nadal aktywną rezerwacją (rozjazd sagi)', 'ordersSettledWithActiveReservation'],
            'ordering_sweep_last_run_age_seconds' => ['Czas od ostatniego przebiegu sweepu wygaszania zamówień (s)', 'orderingSweepLastRunAgeSeconds'],
            'failed_jobs' => ['Liczba zadań w tabeli failed_jobs', 'failedJobs'],
        ];

        foreach ($gauges as $name => [$help, $method]) {
            Prometheus::addGauge($name)
                ->helpText($help)
                ->value(fn () => $this->sample($name, fn () => app(CatalogGauges::class)->{$method}()));
        }
    }

    /**
     * Awaria jednego zapytania (np. brak tabeli, zerwane połączenie) nie może
     * zrobić 500 z całego /prometheus: Prometheus odrzuciłby wtedy wszystkie
     * metryki naraz, a alert o martwym scrape'ie nie powie, co poszło nie tak.
     * Zwracamy brak próbki (pusta tablica: pakiet pomija wtedy linię metryki),
     * a przyczynę zostawiamy w logu.
     *
     * @return array{}|float
     */
    private function sample(string $metric, callable $read): array|float
    {
        try {
            return $read() ?? [];
        } catch (Throwable $e) {
            Log::warning('Gauge Prometheusa nie policzony, pomijam próbkę', [
                'metric' => $metric,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
