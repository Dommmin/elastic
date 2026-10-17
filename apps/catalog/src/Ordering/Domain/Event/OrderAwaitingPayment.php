<?php

namespace Ordering\Domain\Event;

use DateTimeImmutable;
use Ordering\Domain\OrderId;
use SharedKernel\DomainEvent;

/**
 * Wszystkie pozycje zarezerwowane: kupujący może płacić do `deadline`.
 */
final readonly class OrderAwaitingPayment implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public DateTimeImmutable $deadline,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
