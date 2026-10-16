<?php

namespace Ordering\Application\Port;

use DateTimeImmutable;
use Ordering\Domain\OrderId;
use Ordering\Domain\PaymentId;
use Ordering\Domain\RefundId;
use SharedKernel\Money;

/**
 * Port zewnętrznego dostawcy płatności. Wymiana dostawcy to nowy adapter,
 * bez zmian w domenie i handlerach.
 */
interface PaymentGateway
{
    /**
     * Otwiera nową sesję płatności (każde „Zapłać” to nowa sesja, H7).
     * `$expiresAt` = termin zamówienia: bramka nie przyjmie wpłaty po nim.
     *
     * @return string adres, na który przekierować kupującego
     *
     * @throws PaymentGatewayUnavailable
     */
    public function startPayment(OrderId $orderId, Money $amount, DateTimeImmutable $expiresAt, string $returnUrl): string;

    /**
     * `$refundId` to klucz idempotencji: ten sam zwrot zlecony ponownie
     * (ponowiony job) bramka wykona raz.
     *
     * @throws PaymentGatewayUnavailable przy porażce; wołający ponawia
     */
    public function refund(RefundId $refundId, PaymentId $paymentId, Money $amount): void;
}
