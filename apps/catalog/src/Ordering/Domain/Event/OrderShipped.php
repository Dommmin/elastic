<?php

namespace Ordering\Domain\Event;

use DateTimeImmutable;
use Ordering\Domain\OrderId;
use Ordering\Domain\TrackingNumber;
use SharedKernel\DomainEvent;

/**
 * Sprzedawca nadał przesyłkę.
 */
final readonly class OrderShipped implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public TrackingNumber $trackingNumber,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
