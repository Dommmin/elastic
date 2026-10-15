<?php

namespace SellerPortal\Application\Port;

use SellerPortal\Domain\Seller\SellerId;

/**
 * Port kolejki polityk: listener zdarzenia tylko zleca pracę, wykonuje ją
 * worker. DLACZEGO port: Application nie zna Laravela, a implementacja ma
 * wstawić job w tej samej transakcji co zapis sprzedawcy (albo blokada
 * i job, albo nic).
 */
interface PolicyJobQueue
{
    public function suspendOffersOf(SellerId $sellerId): void;

    public function reinstateOffersOf(SellerId $sellerId): void;
}
