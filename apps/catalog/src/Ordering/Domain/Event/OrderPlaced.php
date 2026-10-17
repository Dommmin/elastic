<?php

namespace Ordering\Domain\Event;

use DateTimeImmutable;
use Ordering\Domain\BuyerId;
use Ordering\Domain\OrderId;
use Ordering\Domain\SellerId;
use SharedKernel\DomainEvent;
use SharedKernel\Money;

/**
 * Zamówienie złożone. Process manager zaczyna od niego sagę (rezerwacja pozycji).
 */
final readonly class OrderPlaced implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public BuyerId $buyerId,
        public SellerId $sellerId,
        public Money $total,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
