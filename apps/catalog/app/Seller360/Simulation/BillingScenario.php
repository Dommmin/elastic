<?php

namespace App\Seller360\Simulation;

use Billing\Domain\BillingAccountRepository;
use Billing\Domain\InvoiceId;
use DateTimeImmutable;
use Random\Randomizer;

/**
 * Faktury syntetycznego sprzedawcy z ostatnich 90 dni, zapisane drogą domenową (`BillingAccount`
 * + repozytorium, więc zdarzenia `billing.*` idą przez outbox do projektora sekcji `billing`).
 *
 * Trzy faktury z terminem 14 dni: wystawione ~85, ~55 i ~7 dni temu. Pierwsza zawsze zapłacona,
 * trzecia otwarta z terminem w przyszłości. Druga (termin już minął) jest zapłacona albo — u sprzedawcy
 * z zaległością — niezapłacona i oznaczona `markOverdue(now)`, co daje `overdue_cents > 0`.
 * Zaległość ma około 15% zwykłych sprzedawców; zasadzeni i bliskie pudła mają ją zawsze, poza pudłem
 * „overdue”, które nie ma jej nigdy.
 */
final class BillingScenario
{
    public const OVERDUE_SHARE = 0.15;

    private const PAYMENT_TERM_DAYS = 14;

    /** Przedziały „ile dni temu wystawiona” dla trzech faktur. */
    private const ISSUED_DAYS_AGO = [[80, 89], [50, 59], [5, 9]];

    public function __construct(private readonly BillingAccountRepository $accounts) {}

    /**
     * @return array{overdue: bool, invoices: list<array{days_ago: int, amount_cents: int}>}
     */
    public static function draw(Randomizer $random, ?string $role): array
    {
        $overdue = $role === null
            ? $random->getFloat(0, 1) < self::OVERDUE_SHARE
            : PlantedSellers::satisfies($role, 'overdue');

        $invoices = [];
        foreach (self::ISSUED_DAYS_AGO as [$min, $max]) {
            $invoices[] = ['days_ago' => $random->getInt($min, $max), 'amount_cents' => $random->getInt(5_000, 200_000)];
        }

        return ['overdue' => $overdue, 'invoices' => $invoices];
    }

    /**
     * @param  array{overdue: bool, invoices: list<array{days_ago: int, amount_cents: int}>}  $plan
     */
    public function apply(int $sellerId, array $plan, Randomizer $random, DateTimeImmutable $now): void
    {
        $account = $this->accounts->getOrOpen($sellerId);

        $ids = [];
        foreach ($plan['invoices'] as $invoice) {
            $issuedAt = $now->modify("-{$invoice['days_ago']} days");
            $id = InvoiceId::fromString(DeterministicUlid::make($issuedAt, $random));
            $account->issueInvoice($id, $invoice['amount_cents'], $issuedAt, $issuedAt->modify('+'.self::PAYMENT_TERM_DAYS.' days'));
            $ids[] = [$id, $issuedAt];
        }

        // Zapłata kilka dni po wystawieniu, przed terminem.
        $account->recordPayment($ids[0][0], $ids[0][1]->modify('+3 days'));
        if (! $plan['overdue']) {
            $account->recordPayment($ids[1][0], $ids[1][1]->modify('+5 days'));
        }
        $account->markOverdue($now);

        $this->accounts->save($account);
    }
}
