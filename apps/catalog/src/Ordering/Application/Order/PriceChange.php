<?php

namespace Ordering\Application\Order;

/**
 * Jedna różnica ceny między koszykiem a ofertą, żeby UI oznaczyło pozycję i pokazało nową cenę.
 */
final readonly class PriceChange
{
    public function __construct(
        public int $offerId,
        public int $expectedCents,
        public int $currentCents,
    ) {}
}
