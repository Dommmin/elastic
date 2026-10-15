<?php

namespace SellerPortal\Domain\Seller\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Shared\DomainEvent;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Reason;

/**
 * Moderator zablokował aktywnego sprzedawcę. Reakcję (zawieszenie jego ofert) zleca polityka w warstwie aplikacji, nie sam agregat.
 */
final readonly class SellerBlocked implements DomainEvent
{
    public function __construct(
        public SellerId $sellerId,
        public ModeratorId $moderatorId,
        public Reason $reason,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
