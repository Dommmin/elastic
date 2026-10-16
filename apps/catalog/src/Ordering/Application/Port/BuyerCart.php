<?php

namespace Ordering\Application\Port;

use Ordering\Domain\BuyerId;
use Ordering\Domain\OfferId;

/**
 * Port koszyka kupującego. Czyszczony w tej samej transakcji, w której
 * powstają zamówienia: podwójny klik „Złóż zamówienia” widzi już pusty
 * koszyk (R9).
 *
 * DLACZEGO tylko podane oferty, a nie cały koszyk: w innej karcie kupujący
 * mógł w międzyczasie dodać coś nowego. Tej pozycji nie zamówił, więc nie
 * może zniknąć razem ze złożonymi.
 */
interface BuyerCart
{
    /**
     * @param  list<OfferId>  $offerIds  oferty, z których właśnie powstały zamówienia
     */
    public function clear(BuyerId $buyerId, array $offerIds): void;
}
