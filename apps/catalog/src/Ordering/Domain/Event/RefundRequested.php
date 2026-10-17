<?php

namespace Ordering\Domain\Event;

use DateTimeImmutable;
use Ordering\Domain\OrderId;
use Ordering\Domain\PaymentId;
use Ordering\Domain\RefundId;
use Ordering\Domain\RefundReason;
use SharedKernel\DomainEvent;
use SharedKernel\Money;

/**
 * Zlecony zwrot wpłaty; saga woła bramkę z `refundId` jako kluczem idempotencji.
 */
final readonly class RefundRequested implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public RefundId $refundId,
        public PaymentId $paymentId,
        public Money $amount,
        public RefundReason $reason,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
