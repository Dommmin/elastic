<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Shared\DomainEvent;

/**
 * Zawieszona oferta wróciła do sprzedaży.
 */
final readonly class OfferReinstated implements DomainEvent
{
    public function __construct(
        public OfferId $offerId,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
