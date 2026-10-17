<?php

namespace Ordering\Domain\Event;

use DateTimeImmutable;
use Ordering\Domain\OfferId;
use Ordering\Domain\OrderId;
use SharedKernel\DomainEvent;

/**
 * Sztuki jednej pozycji zarezerwowane w ofercie.
 */
final readonly class OrderLineReserved implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public OfferId $offerId,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
