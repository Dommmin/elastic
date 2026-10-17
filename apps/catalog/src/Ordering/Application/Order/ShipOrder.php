<?php

namespace Ordering\Application\Order;

/**
 * Komenda: sprzedawca oznacza opłacone zamówienie jako wysłane.
 */
final readonly class ShipOrder
{
    public function __construct(
        public int $sellerUserId,
        public int $orderId,
        public string $trackingNumber,
    ) {}
}
