<?php

namespace Billing\Infrastructure;

use Billing\Domain\BillingAccount;
use Billing\Domain\BillingAccountRepository;
use Billing\Domain\Invoice;
use Billing\Domain\InvoiceId;
use Billing\Domain\InvoiceStatus;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use SellerPortal\Infrastructure\Persistence\SqlTimestamp;
use SharedKernel\ConcurrencyConflict;
use UnexpectedValueException;

/**
 * Repozytorium kont rozliczeniowych na Query Builderze (bez Eloquenta, ADR-0002).
 *
 * Optymistyczna blokada po `billing_accounts.version` jak w `SqlSellerRepository`,
 * zdarzenia `billing.*` do outboxu w tej samej transakcji co stan. Pierwszy zapis
 * (wersja 0) to INSERT: równoległe otwarcie tego samego konta przegrywa na kluczu
 * głównym i dostaje ConcurrencyConflict. `save()` nie zmienia obiektu w pamięci.
 */
final class SqlBillingAccountRepository implements BillingAccountRepository
{
    public function getOrOpen(int $sellerId): BillingAccount
    {
        $account = DB::table('billing_accounts')->where('seller_id', $sellerId)->first();

        if ($account === null) {
            return BillingAccount::open($sellerId);
        }

        $invoices = DB::table('invoices')->where('seller_id', $sellerId)->orderBy('issued_at')->orderBy('id')->get()
            ->map(fn (object $row): Invoice => $this->hydrateInvoice($row))
            ->all();

        return BillingAccount::reconstitute($sellerId, $invoices, (int) $account->version);
    }

    public function page(int $after, int $limit): array
    {
        $rows = DB::table('billing_accounts')->where('seller_id', '>', $after)->orderBy('seller_id')->limit($limit)->get();

        // Jedno zapytanie o faktury całej strony zamiast N+1.
        $invoices = DB::table('invoices')->whereIn('seller_id', $rows->pluck('seller_id')->all())
            ->orderBy('issued_at')->orderBy('id')->get()
            ->groupBy('seller_id');

        return $rows->map(fn (object $row): BillingAccount => BillingAccount::reconstitute(
            (int) $row->seller_id,
            ($invoices[$row->seller_id] ?? collect())->map(fn (object $invoice): Invoice => $this->hydrateInvoice($invoice))->values()->all(),
            (int) $row->version,
        ))->all();
    }

    public function save(BillingAccount $account): void
    {
        DB::transaction(function () use ($account): void {
            $newVersion = $account->version() + 1;

            if ($account->version() === 0) {
                $inserted = DB::table('billing_accounts')->insertOrIgnore([
                    'seller_id' => $account->sellerId(),
                    'version' => $newVersion,
                ]);

                if ($inserted === 0) {
                    throw new ConcurrencyConflict;
                }
            } else {
                $updated = DB::table('billing_accounts')
                    ->where('seller_id', $account->sellerId())
                    ->where('version', $account->version())
                    ->update(['version' => $newVersion]);

                if ($updated === 0) {
                    throw new ConcurrencyConflict;
                }
            }

            $rows = array_map(fn (Invoice $invoice): array => [
                'id' => $invoice->id->value(),
                'seller_id' => $account->sellerId(),
                'amount_cents' => $invoice->amountCents,
                'issued_at' => SqlTimestamp::format($invoice->issuedAt),
                'due_at' => SqlTimestamp::format($invoice->dueAt),
                'paid_at' => $invoice->paidAt() === null ? null : SqlTimestamp::format($invoice->paidAt()),
                'status' => $invoice->status()->value,
            ], $account->invoices());

            if ($rows !== []) {
                DB::table('invoices')->upsert($rows, ['id'], ['paid_at', 'status']);
            }

            BillingIntegrationEvents::record($account, $account->pendingEvents(), $newVersion);
        });
    }

    public function sellerIdsWithInvoicesDueBefore(DateTimeImmutable $now): array
    {
        return DB::table('invoices')
            ->where('status', InvoiceStatus::Open->value)
            ->where('due_at', '<', SqlTimestamp::format($now))
            ->distinct()
            ->orderBy('seller_id')
            ->pluck('seller_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function hydrateInvoice(object $row): Invoice
    {
        return new Invoice(
            InvoiceId::fromString((string) $row->id),
            (int) $row->amount_cents,
            SqlTimestamp::parse((string) $row->issued_at),
            SqlTimestamp::parse((string) $row->due_at),
            InvoiceStatus::tryFrom((string) $row->status) ?? throw new UnexpectedValueException("nieznany status faktury '{$row->status}'"),
            $row->paid_at === null ? null : SqlTimestamp::parse((string) $row->paid_at),
        );
    }
}
