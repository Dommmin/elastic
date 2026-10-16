<?php

namespace Tests\Unit\Ordering\InMemory;

use DateTimeImmutable;
use Ordering\Application\Port\PaymentGateway;
use Ordering\Application\Port\PaymentGatewayUnavailable;
use Ordering\Domain\OrderId;
use Ordering\Domain\PaymentId;
use Ordering\Domain\RefundId;
use SharedKernel\Money;

/**
 * Bramka w pamięci: zapisuje sesje i zwroty, a `failNextRefunds` udaje
 * awarię dostawcy, żeby testy sagi sprawdziły ponowienia zwrotu.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var list<array{orderId: OrderId, amount: Money, expiresAt: DateTimeImmutable, returnUrl: string}> */
    public array $startedPayments = [];

    /** @var list<array{refundId: RefundId, paymentId: PaymentId, amount: Money}> udane zwroty */
    public array $refunds = [];

    /** Wszystkie próby zwrotu, także nieudane. */
    public int $refundAttempts = 0;

    private int $failingRefunds = 0;

    public function failNextRefunds(int $count): void
    {
        $this->failingRefunds = $count;
    }

    public function startPayment(OrderId $orderId, Money $amount, DateTimeImmutable $expiresAt, string $returnUrl): string
    {
        $this->startedPayments[] = ['orderId' => $orderId, 'amount' => $amount, 'expiresAt' => $expiresAt, 'returnUrl' => $returnUrl];

        return 'https://gateway.test/pay/'.count($this->startedPayments);
    }

    public function refund(RefundId $refundId, PaymentId $paymentId, Money $amount): void
    {
        $this->refundAttempts++;

        if ($this->failingRefunds > 0) {
            $this->failingRefunds--;

            throw new PaymentGatewayUnavailable;
        }

        $this->refunds[] = ['refundId' => $refundId, 'paymentId' => $paymentId, 'amount' => $amount];
    }
}
