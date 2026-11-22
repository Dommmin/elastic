<?php

namespace Billing\Application;

use Billing\Domain\InvoiceId;
use DateTimeImmutable;

final readonly class RecordPayment
{
    public function __construct(
        public int $sellerId,
        public InvoiceId $invoiceId,
        public ?DateTimeImmutable $paidAt = null,
    ) {}
}
