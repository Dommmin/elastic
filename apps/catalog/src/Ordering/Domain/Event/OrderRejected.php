<?php

namespace Ordering\Domain\Event;

use DateTimeImmutable;
use Ordering\Domain\OfferId;
use Ordering\Domain\OrderId;
use Ordering\Domain\RejectionReason;
use SharedKernel\DomainEvent;

/**
 * Rezerwacja odmówiła na jednej ofercie; saga zwalnia wszystkie pozycje.
 */
final readonly class OrderRejected implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public RejectionReason $reason,
        public OfferId $offerId,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
