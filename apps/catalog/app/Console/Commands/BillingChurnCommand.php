<?php

namespace App\Console\Commands;

use Billing\Application\IssueInvoice;
use Billing\Application\IssueInvoiceHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `php artisan billing:churn` — ruch billingowy pod przebudowę seller-360 (ETAP 13, Task 14): co tik nowa faktura
 * dla losowego SYNTETYCZNEGO sprzedawcy przez `IssueInvoiceHandler`, więc zdarzenie `billing.invoice_issued`
 * z pełnym snapshotem konta trafia do outboxu w tej samej transakcji. Generator `seller360:simulate --billing`
 * nie nadaje się do tego: odmawia, gdy syntetyczni sprzedawcy już są w bazie.
 *
 * Ostatnia linia: `issued=… failed=… since=<ISO 8601>`. Gdy nie udała się żadna próba albo nie ma syntetycznych
 * sprzedawców, kod błędu.
 */
class BillingChurnCommand extends Command
{
    protected $signature = 'billing:churn
        {--rate=10 : Faktur na sekundę}
        {--duration=60 : Czas trwania w sekundach}';

    protected $description = 'Wystawia faktury losowym syntetycznym sprzedawcom (ruch billing.* pod przebudowę seller-360)';

    private const PAYMENT_TERM_DAYS = 14;

    public function handle(IssueInvoiceHandler $issue): int
    {
        $rate = (int) $this->option('rate');
        $duration = (int) $this->option('duration');
        if ($rate < 1 || $duration < 1) {
            $this->error('--rate i --duration muszą być ≥ 1.');

            return self::FAILURE;
        }

        /** @var list<int> $sellers */
        $sellers = DB::table('sellers')->where('synthetic', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($sellers === []) {
            $this->error('Brak syntetycznych sprzedawców (najpierw make seller360-simulate).');

            return self::FAILURE;
        }

        $since = now()->toIso8601String();
        $interval = 1.0 / $rate;
        $deadline = microtime(true) + $duration;
        $next = microtime(true);
        $issued = 0;
        $failed = 0;
        $lastError = '';

        while (microtime(true) < $deadline) {
            try {
                $issue->handle(new IssueInvoice(
                    $sellers[array_rand($sellers)],
                    mt_rand(5_000, 200_000),
                    now()->addDays(self::PAYMENT_TERM_DAYS)->toDateTimeImmutable(),
                ));
                $issued++;
            } catch (Throwable $e) {
                $failed++;
                $lastError = $e::class.': '.$e->getMessage();
            }

            // Równe tempo bez dryfu (jak marketplace:churn).
            $next += $interval;
            $sleep = $next - microtime(true);
            if ($sleep > 0) {
                usleep((int) ($sleep * 1_000_000));
            }
        }

        if ($failed > 0) {
            $this->warn("Uwaga: {$failed} prób zakończyło się błędem. Ostatni: {$lastError}");
        }
        $this->info("issued={$issued} failed={$failed} since={$since}");

        return $issued === 0 ? self::FAILURE : self::SUCCESS;
    }
}
