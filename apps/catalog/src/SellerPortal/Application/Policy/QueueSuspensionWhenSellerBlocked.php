<?php

namespace SellerPortal\Application\Policy;

use SellerPortal\Application\Port\PolicyJobQueue;
use SellerPortal\Domain\Seller\Event\SellerBlocked;

/**
 * Polityka „SellerBlocked → zawieś oferty”: tylko kolejkuje.
 * DLACZEGO nie zawiesza od razu: sprzedawca może mieć tysiące ofert, a
 * handler blokady działa w jednej transakcji w żądaniu HTTP moderatora.
 * Praca idzie do workera; kolejka jest w tej samej bazie, więc job
 * powstaje atomowo z blokadą.
 */
final readonly class QueueSuspensionWhenSellerBlocked
{
    public function __construct(private PolicyJobQueue $queue) {}

    public function __invoke(SellerBlocked $event): void
    {
        $this->queue->suspendOffersOf($event->sellerId);
    }
}
