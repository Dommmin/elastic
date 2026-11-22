<?php

namespace Billing\Domain\Event;

use Billing\Domain\InvoiceId;
use DateTimeImmutable;
use SharedKernel\DomainEvent;

/**
 * Zaksięgowano płatność faktury; saldo i zaległość maleją o jej kwotę.
 */
final readonly class PaymentReceived implements DomainEvent
{
    public function __construct(
        public int $sellerId,
        public InvoiceId $invoiceId,
        public int $amountCents,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
