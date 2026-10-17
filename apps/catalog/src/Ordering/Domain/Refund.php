<?php

namespace Ordering\Domain;

use DateTimeImmutable;
use SharedKernel\Money;

/**
 * Zwrot jednej wpłaty (część agregatu Order).
 *
 * DLACZEGO zwrot żyje w zamówieniu, a nie osobno: o tym, czy wpłata należy
 * się z powrotem, decyduje status zamówienia w chwili jej nadejścia (H6),
 * a „ta wpłata ma już zwrot” to warunek idempotencji tej samej decyzji (H10).
 * Niezmienny: zakończenie daje nowy obiekt (`complete`).
 */
final readonly class Refund
{
    public function __construct(
        public RefundId $refundId,
        public PaymentId $paymentId,
        public Money $amount,
        public RefundReason $reason,
        public RefundStatus $status,
        public DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $completedAt,
    ) {}

    public static function request(RefundId $refundId, PaymentId $paymentId, Money $amount, RefundReason $reason, DateTimeImmutable $now): self
    {
        return new self($refundId, $paymentId, $amount, $reason, RefundStatus::Requested, $now, null);
    }

    public function complete(DateTimeImmutable $now): self
    {
        return new self($this->refundId, $this->paymentId, $this->amount, $this->reason, RefundStatus::Completed, $this->requestedAt, $now);
    }

    public function isCompleted(): bool
    {
        return $this->status === RefundStatus::Completed;
    }
}
