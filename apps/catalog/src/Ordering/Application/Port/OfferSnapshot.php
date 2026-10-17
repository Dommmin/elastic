<?php

namespace Ordering\Application\Port;

use Ordering\Domain\OfferId;
use Ordering\Domain\ProductName;
use Ordering\Domain\SellerId;
use SharedKernel\Money;

/**
 * Oferta widziana przy składaniu zamówienia: tylko to, czego potrzebuje
 * Ordering. DLACZEGO bez dostępności: o niej decyduje rezerwacja, a odczyt
 * przy składaniu i tak byłby nieaktualny (spec, sekcja 4).
 * `sellerUserId` służy wyłącznie regule „nie kupisz własnej oferty” (H12).
 */
final readonly class OfferSnapshot
{
    public function __construct(
        public OfferId $offerId,
        public SellerId $sellerId,
        public int $sellerUserId,
        public ProductName $productName,
        public Money $price,
        public bool $listed,
    ) {}
}
