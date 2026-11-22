<?php

namespace Billing\Domain;

/**
 * Open --markOverdue--> Overdue; Open|Overdue --recordPayment--> Paid (koniec).
 */
enum InvoiceStatus: string
{
    case Open = 'open';
    case Overdue = 'overdue';
    case Paid = 'paid';

    public function isUnpaid(): bool
    {
        return $this !== self::Paid;
    }
}
