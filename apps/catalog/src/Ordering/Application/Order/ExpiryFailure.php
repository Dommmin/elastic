<?php

namespace Ordering\Application\Order;

use Ordering\Domain\OrderId;
use Throwable;

/**
 * Zamówienie, którego sweep nie wygasił przez błąd inny niż konflikt wersji.
 */
final readonly class ExpiryFailure
{
    public function __construct(
        public OrderId $orderId,
        public Throwable $error,
    ) {}
}
