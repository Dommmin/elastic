<?php

namespace Tests\Unit\Ordering\InMemory;

use Ordering\Application\Port\ReservationResult;
use Ordering\Application\Port\StockReservations;
use Ordering\Domain\LineQuantity;
use Ordering\Domain\OfferId;
use SharedKernel\Money;

/**
 * Rezerwacje w pamięci ze szwami dla testów sagi i wyścigów:
 * - `script` wymusza wynik dla oferty (domyślnie `Reserved`),
 * - `stock` symuluje stan oferty: rezerwacja ponad dostępne → `OutOfStock`,
 *   potwierdzenie zmniejsza stan (jak `Offer::confirmReservation`),
 * - `afterReserve` wstrzykuje krok „w trakcie” (np. anulowanie, R1),
 * - `calls` zapisuje każde wywołanie z kluczem, `active` aktywne rezerwacje.
 * Idempotencja jak w portalu: ponowna rezerwacja aktywnego klucza to
 * `Reserved` bez drugiej rezerwacji (R2), zwolnienie nieistniejącej to no-op.
 */
final class FakeStockReservations implements StockReservations
{
    /** @var list<array{op: string, key: string, offerId: int}> */
    public array $calls = [];

    /** @var array<string, array{key: string, offerId: int, quantity: int}> klucz = `{key}|{offerId}` */
    public array $active = [];

    /** Liczba rezerwacji, które naprawdę zajęły sztuki (bez idempotentnych powtórzeń). */
    public int $newReservations = 0;

    /** @var array<int, ReservationResult> */
    private array $scripted = [];

    /** @var array<int, int> stan oferty; brak wpisu = bez limitu */
    private array $stock = [];

    /** @var (callable(string, OfferId): void)|null */
    private $afterReserve = null;

    public function script(int $offerId, ReservationResult $result): void
    {
        $this->scripted[$offerId] = $result;
    }

    public function stock(int $offerId, int $stock): void
    {
        $this->stock[$offerId] = $stock;
    }

    public function stockOf(int $offerId): ?int
    {
        return $this->stock[$offerId] ?? null;
    }

    /**
     * Wołane raz, zaraz po następnej udanej rezerwacji (już zajętej), przed powrotem do sagi.
     *
     * @param  callable(string, OfferId): void  $hook
     */
    public function afterReserve(callable $hook): void
    {
        $this->afterReserve = $hook;
    }

    public function isActive(string $key, int $offerId): bool
    {
        return isset($this->active[$key.'|'.$offerId]);
    }

    public function reserve(string $key, OfferId $offerId, LineQuantity $quantity, Money $unitPrice): ReservationResult
    {
        $this->calls[] = ['op' => 'reserve', 'key' => $key, 'offerId' => $offerId->value()];
        $result = $this->scripted[$offerId->value()] ?? ReservationResult::Reserved;

        if ($result !== ReservationResult::Reserved) {
            return $result;
        }

        if ($this->isActive($key, $offerId->value())) {
            return ReservationResult::Reserved;
        }

        if (isset($this->stock[$offerId->value()]) && $this->available($offerId->value()) < $quantity->value()) {
            return ReservationResult::OutOfStock;
        }

        $this->active[$key.'|'.$offerId->value()] = ['key' => $key, 'offerId' => $offerId->value(), 'quantity' => $quantity->value()];
        $this->newReservations++;

        if ($this->afterReserve !== null) {
            $hook = $this->afterReserve;
            $this->afterReserve = null;
            $hook($key, $offerId);
        }

        return ReservationResult::Reserved;
    }

    public function release(string $key, OfferId $offerId): void
    {
        $this->calls[] = ['op' => 'release', 'key' => $key, 'offerId' => $offerId->value()];
        unset($this->active[$key.'|'.$offerId->value()]);
    }

    public function confirm(string $key, OfferId $offerId): void
    {
        $this->calls[] = ['op' => 'confirm', 'key' => $key, 'offerId' => $offerId->value()];
        $reservation = $this->active[$key.'|'.$offerId->value()] ?? null;

        if ($reservation === null) {
            return;
        }

        if (isset($this->stock[$offerId->value()])) {
            $this->stock[$offerId->value()] -= $reservation['quantity'];
        }

        unset($this->active[$key.'|'.$offerId->value()]);
    }

    /**
     * Stan minus aktywne rezerwacje, jak `available` w ofercie portalu.
     */
    private function available(int $offerId): int
    {
        $reserved = 0;

        foreach ($this->active as $reservation) {
            if ($reservation['offerId'] === $offerId) {
                $reserved += $reservation['quantity'];
            }
        }

        return ($this->stock[$offerId] ?? PHP_INT_MAX) - $reserved;
    }
}
