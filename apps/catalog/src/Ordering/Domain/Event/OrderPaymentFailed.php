<?php

namespace Ordering\Domain\Event;

use DateTimeImmutable;
use Ordering\Domain\OrderId;
use Ordering\Domain\PaymentId;
use SharedKernel\DomainEvent;

/**
 * Bramka odrzuciła płatność. Status się nie zmienia (H7): kupujący może ponowić do terminu.
 */
final readonly class OrderPaymentFailed implements DomainEvent
{
    public function __construct(
        public OrderId $orderId,
        public PaymentId $paymentId,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
