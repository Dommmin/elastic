<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\Stock;
use SellerPortal\Domain\Shared\DomainEvent;

/**
 * Opublikowana oferta spadła do zera sztuk. DLACZEGO osobne zdarzenie: dla reszty systemu to nie 'zmiana liczby', tylko zniknięcie oferty z półki.
 */
final readonly class OfferSoldOut implements DomainEvent
{
    public function __construct(
        public OfferId $offerId,
        public Stock $old,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
