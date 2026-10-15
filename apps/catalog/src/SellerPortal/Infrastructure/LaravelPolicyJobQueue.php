<?php

namespace SellerPortal\Infrastructure;

use SellerPortal\Application\Port\PolicyJobQueue;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Infrastructure\Job\ReinstateOffersOfUnblockedSellerJob;
use SellerPortal\Infrastructure\Job\SuspendOffersOfBlockedSellerJob;

/**
 * DLACZEGO kolejka `database` (domyślne połączenie w prod): polityki mają
 * być atomowe z zapisem sprzedawcy, a to daje tylko kolejka w tej samej
 * bazie — INSERT do `jobs` w bieżącej transakcji, wycofany razem z nią.
 * `after_commit` musi zostać `false` (config/queue.php), inaczej job
 * wyszedłby dopiero po commicie i atomowość by przepadła. Redis nie
 * cofnąłby joba razem z wycofaną blokadą.
 */
final class LaravelPolicyJobQueue implements PolicyJobQueue
{
    public function suspendOffersOf(SellerId $sellerId): void
    {
        SuspendOffersOfBlockedSellerJob::dispatch($sellerId->value());
    }

    public function reinstateOffersOf(SellerId $sellerId): void
    {
        ReinstateOffersOfUnblockedSellerJob::dispatch($sellerId->value());
    }
}
