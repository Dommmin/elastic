<?php

namespace Billing\Application;

use Billing\Application\Port\Clock;
use Billing\Domain\BillingAccountRepository;

/**
 * Sweep zaległości (co 5 minut): oznacza faktury po terminie i emituje po
 * jednym zdarzeniu na fakturę. Idempotentny: oznaczona faktura nie wpada
 * do kolejnego przebiegu.
 */
final readonly class MarkOverdueInvoicesHandler
{
    public function __construct(
        private BillingAccountRepository $accounts,
        private Clock $clock,
    ) {}

    /**
     * @return int liczba faktur, które właśnie przeszły w zaległość
     */
    public function handle(): int
    {
        $now = $this->clock->now();
        $marked = 0;

        foreach ($this->accounts->sellerIdsWithInvoicesDueBefore($now) as $sellerId) {
            $marked += RetryOnConflict::run(function () use ($sellerId, $now): int {
                $account = $this->accounts->getOrOpen($sellerId);
                $account->markOverdue($now);
                $count = count($account->pendingEvents());

                if ($count > 0) {
                    $this->accounts->save($account);
                }

                return $count;
            });
        }

        return $marked;
    }
}
