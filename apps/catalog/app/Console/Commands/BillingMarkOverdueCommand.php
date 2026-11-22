<?php

namespace App\Console\Commands;

use Billing\Application\MarkOverdueInvoicesHandler;
use Illuminate\Console\Command;

/**
 * Sweep zaległości faktur (co 5 minut, `routes/console.php`). Każda faktura,
 * która właśnie przeszła w zaległość, daje zdarzenie `billing.invoice_overdue`.
 */
class BillingMarkOverdueCommand extends Command
{
    protected $signature = 'billing:mark-overdue';

    protected $description = 'Oznacza faktury po terminie jako zaległe i publikuje billing.invoice_overdue';

    public function handle(MarkOverdueInvoicesHandler $handler): int
    {
        $this->info('Faktur w zaległości: '.$handler->handle());

        return self::SUCCESS;
    }
}
