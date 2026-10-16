<?php

namespace SellerPortal\Domain\Seller\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Shared\ModeratorId;
use SharedKernel\DomainEvent;

/**
 * Moderator zdjął blokadę — sprzedawca znów jest aktywny.
 */
final readonly class SellerUnblocked implements DomainEvent
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
