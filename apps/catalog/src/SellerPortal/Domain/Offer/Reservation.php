<?php

namespace SellerPortal\Domain\Offer;

use DateTimeImmutable;

/**
 * Aktywna rezerwacja sztuk oferty (część agregatu Offer).
 *
 * DLACZEGO bez statusu: agregat trzyma tylko aktywne rezerwacje. Potwierdzone
 * i zwolnione pamięta repozytorium (`ReservationStatus`), bo do niezmiennika
 * „nie więcej niż dostępne” nie są potrzebne.
 */
final readonly class Reservation
{
    public function __construct(
        public ReservationKey $key,
        public Quantity $quantity,
        public DateTimeImmutable $reservedAt,
    ) {}
}
