<?php

namespace Ordering\Domain;

/**
 * Kto pyta o dozwolone akcje (`Order::allowedActionsFor`).
 */
enum Actor: string
{
    case Buyer = 'buyer';
    case Seller = 'seller';
}
