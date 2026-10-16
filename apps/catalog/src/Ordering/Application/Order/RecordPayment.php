<?php

namespace Ordering\Application\Order;

/**
 * Komenda z webhooka bramki: wpłata zaksięgowana. Same prymitywy z payloadu.
 */
final readonly class RecordPayment
{
    public function __construct(
        public int $orderId,
        public string $paymentId,
        public int $amountCents,
        public string $currency,
    ) {}
}
