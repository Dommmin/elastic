<?php

namespace Ordering\Application\Port;

use Ordering\Domain\BuyerId;

/**
 * Port koszyka kupującego. Czyszczony w tej samej transakcji, w której
 * powstają zamówienia: podwójny klik „Złóż zamówienia” widzi już pusty
 * koszyk (R9).
 */
interface BuyerCart
{
    public function clear(BuyerId $buyerId): void;
}
