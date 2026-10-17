<?php

namespace SellerPortal\Application\Reservation;

/**
 * Komenda systemowa (od kontekstu Ordering): zarezerwuj sztuki oferty.
 * Bez userId i ExpectedVersion: nie robi jej człowiek z formularza. Cena to ta, którą kupujący widział (H5).
 */
final readonly class ReserveStock
{
    public function __construct(
        public string $reservationKey,
        public int $offerId,
        public int $quantity,
        public int $expectedUnitPriceCents,
        public string $currency,
    ) {}
}
