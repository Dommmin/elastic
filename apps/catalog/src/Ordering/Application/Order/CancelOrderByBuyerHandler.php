<?php

namespace Ordering\Application\Order;

use Ordering\Application\Port\Clock;
use Ordering\Application\Port\DomainEventDispatcher;
use Ordering\Application\Port\TransactionManager;
use Ordering\Application\RetryOnConflict;
use Ordering\Domain\BuyerId;
use Ordering\Domain\InvalidOrderTransition;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderNotFound;
use Ordering\Domain\OrderRepository;

/**
 * Handler komendy CancelOrderByBuyer: wczytaj (tylko własne) → `cancelByBuyer` → zapisz → roześlij.
 * DLACZEGO ponowienia: anulowanie ściga się z sagą rezerwacji (R1); po
 * ponownym odczycie agregat sam rozstrzyga, czy anulowanie jest jeszcze dozwolone.
 */
final readonly class CancelOrderByBuyerHandler
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
    public function handle(CancelOrderByBuyer $command): void
    {
        $buyerId = BuyerId::fromInt($command->buyerUserId);
        $orderId = OrderId::fromInt($command->orderId);

        RetryOnConflict::run(fn () => $this->transactions->run(function () use ($buyerId, $orderId): void {
            $order = $this->access->forBuyer($buyerId, $orderId);
            $order->cancelByBuyer($this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch($order->releaseEvents());
        }));
    }
}
