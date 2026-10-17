<?php

namespace SellerPortal\Domain\Seller;

use SharedKernel\DomainException;

/**
 * Repozytorium nie znalazło sprzedawcy o danym identyfikatorze.
 */
final class SellerNotFound extends DomainException
{
    public const string CODE = 'seller_not_found';

    public function __construct(string $message = 'Nie znaleziono sprzedawcy.')
    {
        parent::__construct($message);
    }
}
