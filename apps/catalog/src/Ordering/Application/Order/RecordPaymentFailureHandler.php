<?php

namespace Ordering\Application\Order;

use Ordering\Application\Port\Clock;
use Ordering\Application\Port\DomainEventDispatcher;
use Ordering\Application\Port\TransactionManager;
use Ordering\Application\RetryOnConflict;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderNotFound;
use Ordering\Domain\OrderRepository;
use Ordering\Domain\PaymentId;

/**
 * Handler komendy RecordPaymentFailure: wczytaj → `recordPaymentFailure` → zapisz → roześlij.
 * Status się nie zmienia (H7), ale zdarzenie trafia do historii zamówienia.
 */
final readonly class RecordPaymentFailureHandler
{
    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private OrderRepository $orders,
    ) {}

    /**
     * @throws OrderNotFound
     */
    public function handle(RecordPaymentFailure $command): void
    {
        $orderId = OrderId::fromInt($command->orderId);
        $paymentId = PaymentId::fromString($command->paymentId);

        RetryOnConflict::run(fn () => $this->transactions->run(function () use ($orderId, $paymentId): void {
            $order = $this->orders->get($orderId);
            $order->recordPaymentFailure($paymentId, $this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch($order->releaseEvents());
        }));
    }
}
