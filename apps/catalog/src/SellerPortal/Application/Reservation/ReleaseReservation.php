<?php

namespace SellerPortal\Application\Reservation;

/**
 * Komenda systemowa: zwolnij rezerwację (kompensacja). Ponowienie jest bezpieczne.
 */
final readonly class ReleaseReservation
{
    public function __construct(
        public string $reservationKey,
        public int $offerId,
    ) {}
}
