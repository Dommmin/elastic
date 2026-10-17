<?php

namespace Ordering\Domain\Event;

use DateTimeImmutable;
use Ordering\Domain\OrderId;
use SharedKernel\DomainEvent;

/**
 * Termin płatności i karencja minęły; saga zwalnia rezerwacje.
 */
final readonly class OrderExpired implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
