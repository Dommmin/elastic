<?php

namespace SellerPortal\Application\Seller;

/**
 * Komenda: Moderator odrzuca zgłoszenie sprzedawcy z podaniem powodu.
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class RejectSeller
{
    public function __construct(
        public int $sellerId,
        public int $moderatorId,
        public string $reason,
    ) {}
}
