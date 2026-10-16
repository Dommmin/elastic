<?php

namespace Ordering\Infrastructure;

use App\FakePaymentGateway\FakePaymentGateway;
use DateTimeImmutable;
use Ordering\Application\Port\PaymentGateway;
use Ordering\Application\Port\PaymentGatewayUnavailable;
use Ordering\Domain\OrderId;
use Ordering\Domain\PaymentId;
use Ordering\Domain\RefundId;
use RuntimeException;
use SharedKernel\Money;

/**
 * Adapter portu płatności na symulowaną bramkę. `reference` = `order-{id}`:
 * to jedyna informacja o zamówieniu, którą bramka odsyła w webhooku.
 *
 * DLACZEGO `RuntimeException` zamieniamy na `PaymentGatewayUnavailable`:
 * Application nie zna typów bramki, a job zwrotu ponawia właśnie po tym wyjątku.
 */
final readonly class FakeGatewayPaymentGateway implements PaymentGateway
{
    public function __construct(private FakePaymentGateway $gateway) {}

    public function startPayment(OrderId $orderId, Money $amount, DateTimeImmutable $expiresAt, string $returnUrl): string
    {
        try {
            return $this->gateway->createPayment(
                'order-'.$orderId->value(),
                $amount->cents(),
                $amount->currency()->value,
                $expiresAt,
                $returnUrl,
            )['url'];
        } catch (RuntimeException $e) {
            throw new PaymentGatewayUnavailable(previous: $e);
        }
    }

    public function refund(RefundId $refundId, PaymentId $paymentId, Money $amount): void
    {
        try {
            $this->gateway->refund($paymentId->value(), $amount->cents(), $refundId->value());
        } catch (RuntimeException $e) {
            throw new PaymentGatewayUnavailable(previous: $e);
        }
    }
}
