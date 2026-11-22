<?php

namespace Billing\Domain;

use SharedKernel\DomainException;

final class InvoiceNotFound extends DomainException
{
    public const string CODE = 'invoice_not_found';

    public function __construct(string $message = 'Nie ma takiej faktury na koncie.')
    {
        parent::__construct($message);
    }
}
