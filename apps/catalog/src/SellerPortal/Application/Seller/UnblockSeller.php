<?php

namespace SellerPortal\Application\Seller;

/**
 * Komenda: Moderator odblokowuje sprzedawcę.
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class UnblockSeller
{
    public function __construct(
        public int $sellerId,
        public int $moderatorId,
    ) {}
}
