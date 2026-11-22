<?php

namespace Billing\Domain;

use DateTimeImmutable;
use SharedKernel\InvalidValue;

/**
 * Faktura wewnątrz agregatu BillingAccount; zmienia ją wyłącznie konto,
 * dlatego mutatory są `internal` w duchu (wołane tylko z agregatu).
 */
final class Invoice
{
    public function __construct(
        public readonly InvoiceId $id,
        public readonly int $amountCents,
        public readonly DateTimeImmutable $issuedAt,
        public readonly DateTimeImmutable $dueAt,
        private InvoiceStatus $status = InvoiceStatus::Open,
        private ?DateTimeImmutable $paidAt = null,
    ) {
        if ($amountCents <= 0) {
            throw new InvalidValue('Kwota faktury musi być dodatnia.');
        }
    }

    public function status(): InvoiceStatus
    {
        return $this->status;
    }

    public function paidAt(): ?DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function isUnpaid(): bool
    {
        return $this->status->isUnpaid();
    }

    public function isOverdue(): bool
    {
        return $this->status === InvoiceStatus::Overdue;
    }

    public function isDueBefore(DateTimeImmutable $now): bool
    {
        return $this->status === InvoiceStatus::Open && $this->dueAt < $now;
    }

    public function markOverdue(): void
    {
        $this->status = InvoiceStatus::Overdue;
    }

    public function pay(DateTimeImmutable $paidAt): void
    {
        $this->status = InvoiceStatus::Paid;
        $this->paidAt = $paidAt;
    }
}
