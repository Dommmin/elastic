<?php

namespace SellerPortal\Application\Offer;

/**
 * Komenda: Sprzedawca wycofuje ofertę. DLACZEGO `forUser`, a nie `forActiveSeller`: zablokowany sprzedawca ma móc zdjąć swoje oferty ze sprzedaży.
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class WithdrawOffer
{
    public function __construct(
        public int $userId,
        public int $offerId,
    ) {}
}
