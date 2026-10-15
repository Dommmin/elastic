<?php

namespace SellerPortal\Application\Offer;

/**
 * Komenda: Sprzedawca wycofuje własną, jeszcze nierozpatrzoną zmianę ceny.
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class CancelPendingPriceChange
{
    public function __construct(
        public int $userId,
        public int $offerId,
    ) {}
}
