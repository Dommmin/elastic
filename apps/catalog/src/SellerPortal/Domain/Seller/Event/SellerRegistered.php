<?php

namespace SellerPortal\Domain\Seller\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Shared\UserId;
use SharedKernel\DomainEvent;

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
