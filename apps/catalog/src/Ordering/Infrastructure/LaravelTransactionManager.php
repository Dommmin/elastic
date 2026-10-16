<?php

namespace Ordering\Infrastructure;

use Illuminate\Support\Facades\DB;
use Ordering\Application\Port\TransactionManager;

/**
 * DLACZEGO `DB::transaction`: zapisy repozytoriów zagnieżdżają się w niej
 * jako savepointy, więc cały przepływ handlera (zapis zamówienia i joby
 * wstawiane przez słuchaczy) jest atomowy.
 */
final class LaravelTransactionManager implements TransactionManager
{
    public function run(callable $work): mixed
    {
        return DB::transaction($work);
    }
}
