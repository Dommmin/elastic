<?php

namespace SellerPortal\Application\Seller;

/**
 * Komenda: operator zmienia nazwę sprzedawcy (CLI `marketplace:seller-rename`).
 */
final readonly class RenameSeller
{
    public function __construct(
        public int $sellerId,
        public string $name,
    ) {}
}
