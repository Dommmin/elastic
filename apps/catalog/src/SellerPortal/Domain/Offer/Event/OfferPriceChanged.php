<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Shared\DomainEvent;
use SellerPortal\Domain\Shared\Money;

/**
 * Cena zmieniła się od razu (szkic albo zmiana w granicach progu).
 */
final readonly class OfferPriceChanged implements DomainEvent
{
    public function __construct(
        public OfferId $offerId,
        public Money $old,
        public Money $new,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
