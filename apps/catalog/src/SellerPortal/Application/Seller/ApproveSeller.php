<?php

namespace SellerPortal\Application\Seller;

/**
 * Komenda: Moderator zatwierdza zgłoszenie sprzedawcy.
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class ApproveSeller
{
    public function __construct(
        public int $sellerId,
        public int $moderatorId,
    ) {}
}
