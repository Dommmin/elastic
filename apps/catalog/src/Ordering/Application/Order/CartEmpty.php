<?php

namespace Ordering\Application\Order;

use SharedKernel\DomainException;

/**
 * Nie ma czego zamówić (np. drugie żądanie po podwójnym kliku, R9).
 */
final class CartEmpty extends DomainException
{
    public const string CODE = 'cart_empty';

    public function __construct(string $message = 'Koszyk jest pusty.')
    {
        parent::__construct($message);
    }
}
