<?php

namespace Ordering\Infrastructure\Job;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Ordering\Application\Process\RefundPayment;
use Ordering\Domain\OrderId;
use Ordering\Domain\RefundId;

/**
 * Job workera: zleć w bramce zwrot pieniędzy za zamówienie.
 *
 * DLACZEGO w jobie tylko skalary: payload leży w tabeli `jobs` i musi przeżyć
 * zmiany kodu; krok i jego zależności wyciągamy z kontenera dopiero w `handle()`.
 * Ponowienia są bezpieczne, bo krok sagi jest idempotentny. Po wyczerpaniu
 * prób job ląduje w `failed_jobs`.
 */
final class RefundPaymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly int $orderId,
        public readonly string $refundId,
    ) {}

    public function handle(RefundPayment $step): void
    {
        $step->run(OrderId::fromInt($this->orderId), RefundId::fromString($this->refundId));
    }
}
