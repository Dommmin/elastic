<?php

namespace Ordering\Application\Order;

/**
 * Pozycja koszyka z formularza. `expectedUnitPriceCents` to cena, którą
 * kupujący widział: składanie odmawia, gdy oferta ma już inną (H5).
 */
final readonly class CartLine
{
    public function __construct(
        public int $offerId,
        public int $quantity,
        public int $expectedUnitPriceCents,
    ) {}
}
