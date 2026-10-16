<?php

namespace Ordering\Domain;

use SharedKernel\DomainException;

/**
 * Repozytorium nie znalazło zamówienia (albo należy ono do kogoś innego: UI pokazuje wtedy 404).
 */
final class OrderNotFound extends DomainException
{
    public const string CODE = 'order_not_found';

    public function __construct(string $message = 'Nie znaleziono zamówienia.')
    {
        parent::__construct($message);
    }
}
