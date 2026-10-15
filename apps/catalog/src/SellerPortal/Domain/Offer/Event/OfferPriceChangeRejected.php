<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Shared\DomainEvent;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Money;
use SellerPortal\Domain\Shared\Reason;

/**
 * Moderator odrzucił oczekującą zmianę ceny; obowiązuje stara cena.
 */
final readonly class OfferPriceChangeRejected implements DomainEvent
{
    public function __construct(
        public OfferId $offerId,
        public Money $proposed,
        public ModeratorId $moderatorId,
        public Reason $reason,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
