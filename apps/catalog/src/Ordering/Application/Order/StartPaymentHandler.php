<?php

namespace Ordering\Application\Order;

use Ordering\Application\Port\PaymentGateway;
use Ordering\Domain\BuyerId;
use Ordering\Domain\InvalidOrderTransition;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderNotFound;

/**
 * Handler komendy StartPayment: otwiera sesję płatności w bramce.
 *
 * DLACZEGO bez transakcji i zapisu: sesja niczego nie zmienia w zamówieniu;
 * stan zmieni dopiero webhook (`RecordPayment`). Każde kliknięcie to nowa
 * sesja, więc ponowienie po odmowie karty działa samo (H7).
 * DLACZEGO sesja wygasa w `paymentDeadline`: bramka nie przyjmie wpłaty po
 * terminie, więc zwrot dostaje tylko wpłata, która naprawdę przyszła po czasie
 * (karencja w `Order::WEBHOOK_GRACE`).
 */
final readonly class StartPaymentHandler
{
    public function __construct(
        private OrderAccess $access,
        private PaymentGateway $gateway,
    ) {}

    /**
     * @return string adres sesji płatności w bramce
     *
     * @throws OrderNotFound
     * @throws InvalidOrderTransition
     */
    public function handle(StartPayment $command): string
    {
        $order = $this->access->forBuyer(BuyerId::fromInt($command->buyerUserId), OrderId::fromInt($command->orderId));
        $order->requirePayable();

        return $this->gateway->startPayment($order->id(), $order->total(), $order->paymentDeadline(), $command->returnUrl);
    }
}
