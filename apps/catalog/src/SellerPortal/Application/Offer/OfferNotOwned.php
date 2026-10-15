<?php

namespace SellerPortal\Application\Offer;

use SellerPortal\Domain\Shared\DomainException;

/**
 * Sprzedawca sięga po ofertę innego sprzedawcy (np. ID podmienione w URL).
 * DLACZEGO osobny wyjątek, a nie OfferNotFound: UI mapuje oba na 404, żeby
 * nie zdradzać istnienia cudzej oferty, ale w logach i testach różnica zostaje.
 */
final class OfferNotOwned extends DomainException
{
    public const string CODE = 'offer_not_owned';

    public function __construct(string $message = 'Nie znaleziono oferty.')
    {
        parent::__construct($message);
    }
}
