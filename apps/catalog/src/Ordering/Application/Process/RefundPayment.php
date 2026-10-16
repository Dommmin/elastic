<?php

namespace Ordering\Application\Process;

use Ordering\Application\Port\Clock;
use Ordering\Application\Port\DomainEventDispatcher;
use Ordering\Application\Port\PaymentGateway;
use Ordering\Application\Port\PaymentGatewayUnavailable;
use Ordering\Application\Port\TransactionManager;
use Ordering\Application\RetryOnConflict;
use Ordering\Domain\InvalidOrderTransition;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderRepository;
use Ordering\Domain\Refund;
use Ordering\Domain\RefundId;

/**
 * Krok po `RefundRequested`: zleca zwrot w bramce, potem odnotowuje go w zamówieniu.
 *
 * DLACZEGO bramka przed transakcją, a nie w niej: wywołanie zewnętrzne
 * trzymałoby blokadę zamówienia na czas sieci. Kolejność jest bezpieczna,
 * bo `refundId` to klucz idempotencji: jeśli zapis po udanym zwrocie padnie,
 * ponowiony job zleci ten sam zwrot drugi raz, a bramka wykona go raz.
 * Porażka bramki leci dalej (`PaymentGatewayUnavailable`): zwrot zostaje
 * `Requested` („w toku” w UI), a job ponawia z backoffem.
 */
final readonly class RefundPayment
{
    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private OrderRepository $orders,
        private PaymentGateway $gateway,
    ) {}

    /**
     * @throws PaymentGatewayUnavailable
     * @throws InvalidOrderTransition gdy zamówienie nie zna tego zwrotu (błąd w sadze)
     */
    public function run(OrderId $orderId, RefundId $refundId): void
    {
        $order = $this->orders->get($orderId);
        $refund = $this->find($order->refunds(), $refundId)
            ?? throw InvalidOrderTransition::from($order->status(), 'refund_payment');

        if ($refund->isCompleted()) {
            return;
        }

        $this->gateway->refund($refund->refundId, $refund->paymentId, $refund->amount);

        RetryOnConflict::run(fn () => $this->transactions->run(function () use ($orderId, $refundId): void {
            $order = $this->orders->get($orderId);
            $order->markRefundCompleted($refundId, $this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch($order->releaseEvents());
        }));
    }

    /**
     * @param  list<Refund>  $refunds
     */
    private function find(array $refunds, RefundId $refundId): ?Refund
    {
        foreach ($refunds as $refund) {
            if ($refund->refundId->equals($refundId)) {
                return $refund;
            }
        }

        return null;
    }
}
