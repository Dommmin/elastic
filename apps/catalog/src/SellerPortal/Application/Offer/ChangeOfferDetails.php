<?php

namespace SellerPortal\Application\Offer;

/**
 * Komenda: Sprzedawca zmienia stan egzemplarza i czas wysyłki.
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class ChangeOfferDetails
{
    public function __construct(
        public int $userId,
        public int $offerId,
        public string $condition,
        public int $shippingDays,
    ) {}
}
