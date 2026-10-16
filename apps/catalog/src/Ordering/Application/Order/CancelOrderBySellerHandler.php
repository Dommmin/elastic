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
use Ordering\Domain\Reason;

/**
 * Handler komendy CancelOrderBySeller: wczytaj (tylko własne) → `cancelBySeller` → zapisz → roześlij.
 * Zwrot zleca agregat (`RefundRequested`), wykonuje go saga poza tą transakcją.
 */
final readonly class CancelOrderBySellerHandler
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
    public function handle(CancelOrderBySeller $command): void
    {
        $orderId = OrderId::fromInt($command->orderId);
        $reason = Reason::fromString($command->reason);

        RetryOnConflict::run(fn () => $this->transactions->run(function () use ($command, $orderId, $reason): void {
            $order = $this->access->forSeller($command->sellerUserId, $orderId);
            $order->cancelBySeller($reason, $this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch($order->releaseEvents());
        }));
    }
}
