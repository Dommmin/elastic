<?php

namespace SellerPortal\Domain\Seller\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Shared\DomainEvent;
use SellerPortal\Domain\Shared\UserId;

/**
 * Sprzedawca złożył zgłoszenie; czeka na moderatora.
 */
final readonly class SellerRegistered implements DomainEvent
{
    public function __construct(
        public SellerId $sellerId,
        public UserId $userId,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
