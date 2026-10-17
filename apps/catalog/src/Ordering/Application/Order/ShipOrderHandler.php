<?php

namespace Ordering\Application\Order;

use Ordering\Application\Port\Clock;
use Ordering\Application\Port\DomainEventDispatcher;
use Ordering\Application\Port\TransactionManager;
use Ordering\Application\RetryOnConflict;
use Ordering\Domain\InvalidOrderTransition;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderNotFound;
use Ordering\Domain\OrderRepository;
use Ordering\Domain\TrackingNumber;

/**
 * Handler komendy ShipOrder: wczytaj (tylko własne) → `ship` → zapisz → roześlij.
 */
final readonly class ShipOrderHandler
{
    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private OrderAccess $access,
        private OrderRepository $orders,
    ) {}

    /**
     * @throws OrderNotFound
     * @throws InvalidOrderTransition
     */
    public function handle(ShipOrder $command): void
    {
        $orderId = OrderId::fromInt($command->orderId);
        $trackingNumber = TrackingNumber::fromString($command->trackingNumber);

        RetryOnConflict::run(fn () => $this->transactions->run(function () use ($command, $orderId, $trackingNumber): void {
            $order = $this->access->forSeller($command->sellerUserId, $orderId);
            $order->ship($trackingNumber, $this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch($order->releaseEvents());
        }));
    }
}
