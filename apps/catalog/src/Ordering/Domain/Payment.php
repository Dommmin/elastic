<?php

namespace Ordering\Domain;

use DateTimeImmutable;
use SharedKernel\Money;

/**
 * Zaksięgowana wpłata (część agregatu Order): ta, która zamieniła zamówienie w `Paid`.
 *
 * DLACZEGO tylko jedna: każda kolejna wpłata to pomyłka kupującego albo
 * bramki i dostaje zwrot (`Refund`), więc zamówienie nigdy nie trzyma dwóch
 * „ważnych” płatności.
 */
final readonly class Payment
{
    public function __construct(
        public PaymentId $paymentId,
        public Money $amount,
        public DateTimeImmutable $paidAt,
    ) {}
}
