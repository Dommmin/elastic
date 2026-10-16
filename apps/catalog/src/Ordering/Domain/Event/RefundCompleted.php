<?php

namespace Ordering\Domain\Event;

use DateTimeImmutable;
use Ordering\Domain\OrderId;
use Ordering\Domain\RefundId;
use SharedKernel\DomainEvent;

/**
 * Bramka potwierdziła zwrot.
 */
final readonly class RefundCompleted implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public RefundId $refundId,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
