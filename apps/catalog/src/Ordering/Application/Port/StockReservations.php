<?php

namespace Ordering\Application\Port;

use Ordering\Domain\LineQuantity;
use Ordering\Domain\OfferId;
use SharedKernel\Money;

/**
 * Port rezerwacji stanu w ofertach portalu. Każda operacja to osobna
 * transakcja oferty (nie zamówienia): jeden agregat na transakcję.
 * Wszystkie trzy są idempotentne po kluczu (`order-{id}`), bo saga je ponawia.
 */
interface StockReservations
{
    /**
     * Ponowienie z tym samym kluczem i ilością zwraca `Reserved` bez drugiej rezerwacji (R2).
     */
    public function reserve(string $key, OfferId $offerId, LineQuantity $quantity, Money $unitPrice): ReservationResult;

    /**
     * Brak aktywnej rezerwacji to no-op.
     */
    public function release(string $key, OfferId $offerId): void;

    public function confirm(string $key, OfferId $offerId): void;
}
