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
use SharedKernel\Currency;
use SharedKernel\Money;

/**
 * Handler komendy RecordPayment: wczytaj → `recordPayment` → zapisz → roześlij.
 * DLACZEGO bez sprawdzania właściciela: woła go webhook bramki (podpis
 * sprawdza kontroler), nie człowiek. DLACZEGO ponowienia: webhook ściga się
 * ze sweepem wygaszania (R3); przegrany czyta świeży stan i agregat sam
 * rozstrzyga (zapłacone albo zwrot).
 */
final readonly class RecordPaymentHandler
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
    public function handle(RecordPayment $command): void
    {
        $orderId = OrderId::fromInt($command->orderId);
        $paymentId = PaymentId::fromString($command->paymentId);
        $amount = Money::fromCents($command->amountCents, Currency::from($command->currency));

        RetryOnConflict::run(fn () => $this->transactions->run(function () use ($orderId, $paymentId, $amount): void {
            $order = $this->orders->get($orderId);
            $order->recordPayment($paymentId, $amount, $this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch($order->releaseEvents());
        }));
    }
}
