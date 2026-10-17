<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\Condition;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\ShippingTime;
use SharedKernel\DomainEvent;

/**
 * Zmieniono stan egzemplarza lub czas wysyłki.
 */
final readonly class OfferDetailsChanged implements DomainEvent
{
    public function __construct(
        public OfferId $offerId,
        public Condition $condition,
        public ShippingTime $shippingTime,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
