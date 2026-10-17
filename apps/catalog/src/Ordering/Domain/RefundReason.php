<?php

namespace Ordering\Domain;

/**
 * Dlaczego oddajemy pieniądze. Każdy powód to inna ścieżka w `Order::recordPayment` albo `cancelBySeller`.
 */
enum RefundReason: string
{
    case LatePayment = 'late_payment';
    case DuplicatePayment = 'duplicate_payment';
    case AmountMismatch = 'amount_mismatch';
    case CancelledBySeller = 'cancelled_by_seller';
}
