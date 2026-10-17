<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\Stock;
use SharedKernel\DomainEvent;

/**
 * Wyprzedana, opublikowana oferta znów ma towar.
 */
final readonly class OfferBackInStock implements DomainEvent
{
    public function __construct(
        public OfferId $offerId,
        public Stock $new,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
