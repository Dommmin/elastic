<?php

namespace Ordering\Domain;

/**
 * Status zamówienia. Końcowe: Shipped, Rejected, Expired, Cancelled (końcowe dla statusu; zwroty mogą się jeszcze rozliczać). Wartości to kontrakt z bazą i UI.
 */
enum OrderStatus: string
{
    case Placed = 'placed';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
