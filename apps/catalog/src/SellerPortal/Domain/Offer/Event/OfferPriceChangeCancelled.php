<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\OfferId;
use SharedKernel\DomainEvent;
use SharedKernel\Money;

/**
 * Sprzedawca wycofał własną oczekującą zmianę ceny.
 */
final readonly class OfferPriceChangeCancelled implements DomainEvent
{
    public function __construct(
        public OfferId $offerId,
        public Money $proposed,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
