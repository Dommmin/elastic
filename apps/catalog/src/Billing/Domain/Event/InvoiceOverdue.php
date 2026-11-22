<?php

namespace Billing\Domain\Event;

use Billing\Domain\InvoiceId;
use DateTimeImmutable;
use SharedKernel\DomainEvent;

/**
 * Faktura właśnie przeszła w zaległość (termin minął, brak płatności). Jedno zdarzenie na fakturę.
 */
final readonly class InvoiceOverdue implements DomainEvent
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
