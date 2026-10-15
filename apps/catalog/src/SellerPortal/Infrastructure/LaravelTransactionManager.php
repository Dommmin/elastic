<?php

namespace SellerPortal\Infrastructure;

use Illuminate\Support\Facades\DB;
use SellerPortal\Application\Port\TransactionManager;

/**
 * DLACZEGO `DB::transaction`: zapisy repozytoriów zagnieżdżają się w niej
 * jako savepointy, więc cały przepływ handlera jest atomowy.
 */
final class LaravelTransactionManager implements TransactionManager
{
    public function run(callable $work): mixed
    {
        return DB::transaction($work);
    }
}
