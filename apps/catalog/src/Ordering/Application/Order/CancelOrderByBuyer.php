<?php

namespace Ordering\Application\Order;

/**
 * Komenda: kupujący anuluje zamówienie przed zapłatą.
 */
final readonly class CancelOrderByBuyer
{
    public function __construct(
        public int $buyerUserId,
        public int $orderId,
    ) {}
}
