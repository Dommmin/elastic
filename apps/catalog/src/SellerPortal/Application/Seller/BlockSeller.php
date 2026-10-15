<?php

namespace SellerPortal\Application\Seller;

/**
 * Komenda: Moderator blokuje aktywnego sprzedawcę. Skutek dla ofert realizuje polityka reagująca na SellerBlocked.
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class BlockSeller
{
    public function __construct(
        public int $sellerId,
        public int $moderatorId,
        public string $reason,
    ) {}
}
