<?php

namespace SellerPortal\Application\Policy;

use SellerPortal\Application\Port\PolicyJobQueue;
use SellerPortal\Domain\Seller\Event\SellerUnblocked;

/**
 * Polityka „SellerUnblocked → przywróć oferty”: tylko kolejkuje, z tych
 * samych powodów co `QueueSuspensionWhenSellerBlocked`.
 */
final readonly class QueueReinstatementWhenSellerUnblocked
{
    public function __construct(private PolicyJobQueue $queue) {}

    public function __invoke(SellerUnblocked $event): void
    {
        $this->queue->reinstateOffersOf($event->sellerId);
    }
}
