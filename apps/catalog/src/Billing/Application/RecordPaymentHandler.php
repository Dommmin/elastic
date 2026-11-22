<?php

namespace Billing\Application;

use Billing\Application\Port\Clock;
use Billing\Domain\BillingAccountRepository;

final readonly class RecordPaymentHandler
{
    public function __construct(
        private BillingAccountRepository $accounts,
        private Clock $clock,
    ) {}

    public function handle(RecordPayment $command): void
    {
        RetryOnConflict::run(function () use ($command): void {
            $account = $this->accounts->getOrOpen($command->sellerId);
            $account->recordPayment($command->invoiceId, $command->paidAt ?? $this->clock->now());
            $this->accounts->save($account);
        });
    }
}
