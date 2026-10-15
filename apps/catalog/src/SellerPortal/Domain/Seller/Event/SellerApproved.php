<?php

namespace SellerPortal\Domain\Seller\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Shared\DomainEvent;
use SellerPortal\Domain\Shared\ModeratorId;

/**
 * Moderator zatwierdził zgłoszenie — od teraz sprzedawca może wystawiać oferty.
 */
final readonly class SellerApproved implements DomainEvent
{
    public function __construct(
        public SellerId $sellerId,
        public ModeratorId $moderatorId,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
