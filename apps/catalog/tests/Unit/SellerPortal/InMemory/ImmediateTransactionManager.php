<?php

namespace Tests\Unit\SellerPortal\InMemory;

use SellerPortal\Application\Port\TransactionManager;

/**
 * Bez bazy nie ma czego wycofywać; zlicza wywołania, żeby testy mogły
 * sprawdzić, że handler faktycznie opakował pracę w transakcję.
 */
final class ImmediateTransactionManager implements TransactionManager
{
    public int $runs = 0;

    public function run(callable $work): mixed
    {
        $this->runs++;

        return $work();
    }
}
