<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Ordering\Application\Order\ExpireOverdueOrders;
use Ordering\Application\Order\ExpireOverdueOrdersHandler;

/**
 * Sweep wygaśnięć zamówień (co minutę, `routes/console.php`).
 *
 * DLACZEGO czas przebiegu w cache: `/health` pokazuje go jako sygnał, że
 * scheduler żyje. Bez sweepu zamówienia nie wygasają, a towar zostaje
 * zarezerwowany, więc cisza tego procesu musi być widoczna.
 */
class ExpireOverdueOrdersCommand extends Command
{
    protected $signature = 'orders:expire-overdue';

    protected $description = 'Wygasza zamówienia po terminie płatności i karencji webhooka';

    public function handle(ExpireOverdueOrdersHandler $handler): int
    {
        $expired = $handler->handle(new ExpireOverdueOrders);

        Cache::put('ordering:sweep:last_run_at', now()->toIso8601String());

        $this->info("Wygaszono zamówień: {$expired}");

        return self::SUCCESS;
    }
}
