<?php

namespace Ordering\Application\Port;

/**
 * Port transakcji: handler opakowuje w niej wczytaj → zmień → zapisz →
 * roześlij, żeby stan zamówienia i kroki sagi (joby wstawiane przez
 * słuchaczy) powstawały atomowo albo wcale.
 */
interface TransactionManager
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function run(callable $work): mixed;
}
