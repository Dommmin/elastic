<?php

namespace SellerPortal\Application\Offer;

/**
 * Komenda: Sprzedawca zakłada szkic oferty na produkt z katalogu.
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class DraftOffer
{
    public function __construct(
        public int $userId,
        public int $productId,
        public int $priceCents,
        public int $stock,
        public string $condition,
        public int $shippingDays,
    ) {}
}
