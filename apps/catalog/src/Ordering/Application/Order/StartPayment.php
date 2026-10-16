<?php

namespace Ordering\Application\Order;

/**
 * Komenda: kupujący klika „Zapłać”. `returnUrl` to adres, na który bramka odeśle kupującego.
 */
final readonly class StartPayment
{
    public function __construct(
        public int $buyerUserId,
        public int $orderId,
        public string $returnUrl,
    ) {}
}
