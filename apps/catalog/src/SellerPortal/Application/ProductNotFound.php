<?php

namespace SellerPortal\Application;

use SharedKernel\DomainException;

/**
 * Szkic oferty wskazuje produkt, którego nie ma w katalogu. Katalog to inny kontekst, więc sprawdza to aplikacja przez port, nie agregat.
 */
final class ProductNotFound extends DomainException
{
    public const string CODE = 'product_not_found';

    public function __construct(string $message = 'Nie znaleziono produktu.')
    {
        parent::__construct($message);
    }
}
