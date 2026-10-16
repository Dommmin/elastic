<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\OfferId;
use SharedKernel\DomainEvent;
use SharedKernel\Money;

/**
 * Zmiana ceny przekroczyła próg i czeka na decyzję moderatora; cena obowiązująca się nie zmienia.
 */
final readonly class OfferPriceChangeRequested implements DomainEvent
{
    public function __construct(
        public OfferId $offerId,
        public Money $current,
        public Money $proposed,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
