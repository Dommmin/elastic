<?php

namespace SellerPortal\Application\Reservation;

/**
 * Komenda systemowa: płatność przyszła, sprzedaj zarezerwowane sztuki (zmniejsz stan).
 */
final readonly class ConfirmReservation
{
    public function __construct(
        public string $reservationKey,
        public int $offerId,
    ) {}
}
