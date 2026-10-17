<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Shared\ModeratorId;
use SharedKernel\DomainEvent;
use SharedKernel\Money;

/**
 * Moderator zaakceptował oczekującą zmianę ceny; nowa cena obowiązuje.
 */
final readonly class OfferPriceChangeApproved implements DomainEvent
{
    public function __construct(
        public OfferId $offerId,
        public Money $old,
        public Money $new,
        public ModeratorId $moderatorId,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
