<?php

namespace Billing\Domain;

use SharedKernel\DomainException;

final class InvoiceAlreadyPaid extends DomainException
{
    public const string CODE = 'invoice_already_paid';

    public function __construct(string $message = 'Faktura jest już opłacona.')
    {
        parent::__construct($message);
    }
}
