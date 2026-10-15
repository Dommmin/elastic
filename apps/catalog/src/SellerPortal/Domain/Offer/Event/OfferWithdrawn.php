<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Shared\DomainEvent;

/**
 * Oferta została wycofana (stan końcowy).
 */
final readonly class OfferWithdrawn implements DomainEvent
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
