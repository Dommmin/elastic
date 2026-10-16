<?php

namespace Ordering\Application\Order;

/**
 * Komenda: sprzedawca anuluje opłacone zamówienie z podanym powodem (zwrot całej wpłaty).
 */
final readonly class CancelOrderBySeller
{
    public function __construct(
        public int $sellerUserId,
        public int $orderId,
        public string $reason,
    ) {}
}
