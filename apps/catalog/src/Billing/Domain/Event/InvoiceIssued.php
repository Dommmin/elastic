<?php

namespace Billing\Domain\Event;

use Billing\Domain\InvoiceId;
use DateTimeImmutable;
use SharedKernel\DomainEvent;

/**
 * Wystawiono fakturę; saldo konta rośnie o jej kwotę.
 */
final readonly class InvoiceIssued implements DomainEvent
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
