<?php

namespace Ordering\Application\Order;

/**
 * Komenda z webhooka bramki: płatność odrzucona (karta, odmowa kupującego).
 */
final readonly class RecordPaymentFailure
{
    public function __construct(
        public int $orderId,
        public string $paymentId,
    ) {}
}
