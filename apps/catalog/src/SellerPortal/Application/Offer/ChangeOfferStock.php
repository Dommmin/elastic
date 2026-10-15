<?php

namespace SellerPortal\Application\Offer;

/**
 * Komenda: Sprzedawca zmienia stan magazynowy oferty.
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class ChangeOfferStock
{
    public function __construct(
        public int $userId,
        public int $offerId,
        public int $stock,
    ) {}
}
