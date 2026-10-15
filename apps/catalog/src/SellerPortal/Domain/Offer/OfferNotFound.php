<?php

namespace SellerPortal\Domain\Offer;

use SellerPortal\Domain\Shared\DomainException;

/**
 * Repozytorium nie znalazło oferty o danym identyfikatorze.
 */
final class OfferNotFound extends DomainException
{
    public const string CODE = 'offer_not_found';

    public function __construct(string $message = 'Nie znaleziono oferty.')
    {
        parent::__construct($message);
    }
}
