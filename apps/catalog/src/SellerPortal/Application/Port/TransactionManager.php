<?php

namespace SellerPortal\Application\Port;

/**
 * Port transakcji: handler opakowuje w niej cały przepływ (wczytaj → zmień →
 * zapisz → roześlij zdarzenia), żeby stan i skutki zdarzeń (np. kolejka
 * polityk) powstawały atomowo albo wcale.
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
