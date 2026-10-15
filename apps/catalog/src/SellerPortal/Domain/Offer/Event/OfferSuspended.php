<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\SuspensionReason;
use SellerPortal\Domain\Shared\DomainEvent;

/**
 * Oferta została zawieszona (przez moderatora albo przez blokadę sprzedawcy).
 */
final readonly class OfferSuspended implements DomainEvent
{
    public function __construct(
        public OfferId $offerId,
        public SuspensionReason $reason,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
