<?php

namespace Billing\Application;

use Billing\Application\Port\Clock;
use Billing\Domain\BillingAccountRepository;
use Billing\Domain\InvoiceId;
use Illuminate\Support\Str;

/**
 * Wystawia fakturę na koncie sprzedawcy (konto powstaje przy pierwszej).
 * Identyfikator faktury (ULID) nadaje handler, bo domena nie zna generatora.
 */
final readonly class IssueInvoiceHandler
{
    public function __construct(
        private BillingAccountRepository $accounts,
        private Clock $clock,
    ) {}

    public function handle(IssueInvoice $command): InvoiceId
    {
        $id = InvoiceId::fromString((string) Str::ulid());

        RetryOnConflict::run(function () use ($command, $id): void {
            $account = $this->accounts->getOrOpen($command->sellerId);
            $account->issueInvoice($id, $command->amountCents, $command->issuedAt ?? $this->clock->now(), $command->dueAt);
            $this->accounts->save($account);
        });

        return $id;
    }
}
