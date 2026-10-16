<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Ordering\Application\Order\ExpireOverdueOrders;
use Ordering\Application\Order\ExpireOverdueOrdersHandler;

/**
 * Sweep wygaśnięć zamówień (co minutę, `routes/console.php`).
 *
 * DLACZEGO czas przebiegu w cache: `/health` pokazuje go jako sygnał, że
 * scheduler żyje. Bez sweepu zamówienia nie wygasają, a towar zostaje
 * zarezerwowany, więc cisza tego procesu musi być widoczna.
 *
 * Zamówienia, których nie dało się wygasić, handler zwraca w wyniku (nie zna
 * loggera); tu trafiają do logu z wyjątkiem, a kod wyjścia FAILURE pokazuje
 * je schedulerowi. Czas przebiegu zapisujemy mimo to: sweep żyje, reszta wygasła.
 */
class ExpireOverdueOrdersCommand extends Command
{
    protected $signature = 'orders:expire-overdue';

    protected $description = 'Wygasza zamówienia po terminie płatności i karencji webhooka';

    public function handle(ExpireOverdueOrdersHandler $handler): int
    {
        $result = $handler->handle(new ExpireOverdueOrders);

        Cache::put('ordering:sweep:last_run_at', now()->toIso8601String());

        foreach ($result->failures as $failure) {
            Log::error('Sweep nie wygasił zamówienia — pominięte w tym przebiegu.', [
                'order_id' => $failure->orderId->value(),
                'exception' => $failure->error,
            ]);
        }

        $this->info("Wygaszono zamówień: {$result->expired}");

        if ($result->failures !== []) {
            $this->error('Nie udało się wygasić zamówień: '.count($result->failures).' (szczegóły w logu)');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
