<?php

namespace SellerPortal\Infrastructure;

use SellerPortal\Application\Port\PolicyJobQueue;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Infrastructure\Job\ReinstateOffersOfUnblockedSellerJob;
use SellerPortal\Infrastructure\Job\SuspendOffersOfBlockedSellerJob;

/**
 * DLACZEGO połączenie przypięte (`queue.seller_policies_connection`, domyślnie `database`), a nie `queue.default`: polityki mają
 * być atomowe z zapisem sprzedawcy, a to daje tylko kolejka w tej samej
 * bazie — INSERT do `jobs` w bieżącej transakcji, wycofany razem z nią.
 * `after_commit` musi zostać `false` (config/queue.php), inaczej job
 * wyszedłby dopiero po commicie i atomowość by przepadła. Zmiana QUEUE_CONNECTION
 * (redis, sync) nie może rozpiąć tej gwarancji ani wysłać jobów tam, gdzie worker
 * nie czyta. Redis nie cofnąłby joba razem z wycofaną blokadą.
 */
final class LaravelPolicyJobQueue implements PolicyJobQueue
{
    public function __construct(private readonly string $connection) {}

    public function suspendOffersOf(SellerId $sellerId): void
    {
        SuspendOffersOfBlockedSellerJob::dispatch($sellerId->value())->onConnection($this->connection);
    }

    public function reinstateOffersOf(SellerId $sellerId): void
    {
        ReinstateOffersOfUnblockedSellerJob::dispatch($sellerId->value())->onConnection($this->connection);
    }
}
