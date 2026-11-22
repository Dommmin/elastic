<?php

namespace Billing\Application;

use DateTimeImmutable;

final readonly class IssueInvoice
{
    public function __construct(
        public int $sellerId,
        public int $amountCents,
        public DateTimeImmutable $dueAt,
        public ?DateTimeImmutable $issuedAt = null,
    ) {}
}
