<?php

namespace Ordering\Application\Order;

/**
 * Komenda: kupujący składa zamówienia z całego koszyka (po jednym na sprzedawcę).
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają w handlerze.
 */
final readonly class PlaceOrdersFromCart
{
    /**
     * @param  list<CartLine>  $lines
     */
    public function __construct(
        public int $buyerUserId,
        public array $lines,
    ) {}
}
