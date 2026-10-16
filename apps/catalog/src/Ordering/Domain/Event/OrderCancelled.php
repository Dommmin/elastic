<?php

namespace Ordering\Domain\Event;

use DateTimeImmutable;
use Ordering\Domain\CancelledBy;
use Ordering\Domain\OrderId;
use Ordering\Domain\Reason;
use SharedKernel\DomainEvent;

/**
 * Zamówienie anulowane. `by` decyduje o kompensacji: kupujący → zwolnienie rezerwacji, sprzedawca → zwrot bez zwracania stanu (H8).
 */
final readonly class OrderCancelled implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public CancelledBy $by,
        public ?Reason $reason,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
