<?php

namespace Ordering\Domain\Event;

use DateTimeImmutable;
use Ordering\Domain\OrderId;
use Ordering\Domain\PaymentId;
use SharedKernel\DomainEvent;
use SharedKernel\Money;

/**
 * Wpłata zaksięgowana; saga potwierdza rezerwacje (stan ofert spada).
 */
final readonly class OrderPaid implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public PaymentId $paymentId,
        public Money $amount,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
