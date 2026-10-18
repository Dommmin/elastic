<?php

namespace App\Metrics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ordering\Domain\OrderStatus;

/**
 * Gauge'e katalogu liczone przy scrape, jednym zapytaniem SQL na metrykę.
 *
 * Dlaczego nie liczniki w pamięci procesu: PHP-FPM i workery to wiele krótko
 * żyjących procesów, a licznik w pamięci jednego z nich nie mówi nic o
 * całości. Stan „ile czeka” baza zna dokładnie, więc pytamy ją wprost.
 *
 * Dlaczego klasa leży w `app/`, a nie w `src/`: `ordersSettledWithActiveReservation()`
 * łączy tabele dwóch kontekstów (`orders` z Orderingu i `offer_reservations`
 * z Seller Portalu). Taki JOIN w którymkolwiek z nich złamałby granicę
 * kontekstów, a `app/` to warstwa, która widzi całą bazę (jak kontrolery).
 *
 * Konwencja zwrotu: `0.0` = nic nie czeka (też dla wieków). Tylko
 * `orderingSweepLastRunAgeSeconds()` bywa `null` (sweep jeszcze nie biegł):
 * to brak próbki, czyli linia metryki nie pojawia się.
 */
final class CatalogGauges
{
    /**
     * Zamówienie `placed` żyje normalnie ułamek sekundy: saga rezerwacji od
     * razu przesuwa je do `awaiting_payment`. Po minucie coś utknęło
     * (worker leży albo job wpadł do failed_jobs).
     */
    public const STUCK_PLACED_AFTER_SECONDS = 60;

    public function outboxPending(): float
    {
        return (float) DB::table('outbox')->whereNull('published_at')->count();
    }

    public function outboxOldestPendingAgeSeconds(): float
    {
        return $this->ageOf(DB::table('outbox')->whereNull('published_at')->min('occurred_at'));
    }

    public function offersPendingModeration(): float
    {
        return (float) DB::table('offers')->whereNotNull('pending_requested_at')->count();
    }

    public function offersPendingModerationOldestAgeSeconds(): float
    {
        return $this->ageOf(DB::table('offers')->whereNotNull('pending_requested_at')->min('pending_requested_at'));
    }

    public function sellersPendingApproval(): float
    {
        return (float) DB::table('sellers')->where('status', 'pending')->count();
    }

    public function sellersPendingApprovalOldestAgeSeconds(): float
    {
        // `status_changed_at` jest puste, dopóki status się nie zmienił, więc
        // czekanie liczymy wtedy od założenia konta. coalesce miesza timestamptz
        // z timestamp: zakładamy sesję bazy w UTC (tak jak reszta aplikacji).
        return $this->ageOf(
            DB::table('sellers')->where('status', 'pending')->min(DB::raw('coalesce(status_changed_at, created_at)')),
        );
    }

    public function ordersStuckPlaced(): float
    {
        return (float) DB::table('orders')
            ->where('status', OrderStatus::Placed->value)
            ->where('placed_at', '<', now()->subSeconds(self::STUCK_PLACED_AFTER_SECONDS))
            ->count();
    }

    /**
     * Rozjazd sagi: zamówienie jest już opłacone/wysłane, a rezerwacja nadal
     * `active` (potwierdzenie stanu magazynowego nie doszło). Klucz rezerwacji
     * Orderingu to `order-{id}`.
     */
    public function ordersSettledWithActiveReservation(): float
    {
        return (float) DB::table('orders')
            ->join('offer_reservations', 'offer_reservations.reservation_key', '=', DB::raw("('order-' || orders.id)"))
            ->where('offer_reservations.status', 'active')
            ->whereIn('orders.status', [OrderStatus::Paid->value, OrderStatus::Shipped->value])
            ->distinct()
            ->count('orders.id');
    }

    public function orderingSweepLastRunAgeSeconds(): ?float
    {
        $lastRun = Cache::get('ordering:sweep:last_run_at');

        return $lastRun === null ? null : $this->ageOf($lastRun);
    }

    public function failedJobs(): float
    {
        return (float) DB::table('failed_jobs')->count();
    }

    /** Wiek w sekundach od `$moment`; `0.0`, gdy nic nie czeka (`null` z `min()`). */
    private function ageOf(mixed $moment): float
    {
        if ($moment === null) {
            return 0.0;
        }

        return max(0.0, (float) Carbon::parse($moment)->diffInSeconds(now(), absolute: false));
    }
}
