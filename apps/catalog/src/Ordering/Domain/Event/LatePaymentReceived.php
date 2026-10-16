<?php

namespace Ordering\Domain\Event;

use DateTimeImmutable;
use Ordering\Domain\OrderId;
use Ordering\Domain\PaymentId;
use SharedKernel\DomainEvent;
use SharedKernel\Money;

/**
 * Wpłata, której zamówienie nie może przyjąć (po terminie, podwójna, zła kwota). Zawsze idzie w parze z `RefundRequested`.
 */
final readonly class LatePaymentReceived implements DomainEvent
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
