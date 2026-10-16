<?php

namespace SellerPortal\Domain\Offer;

use SharedKernel\DomainException;

/**
 * Akcja dotyczy oczekującej zmiany ceny, a żadnej nie ma (np. moderator kliknął po tym, jak sprzedawca ją wycofał).
 *
 * DLACZEGO wyjątek, a nie cichy powrót: wyścig dwóch kart ma być widoczny.
 */
final class NoPendingPriceChange extends DomainException
{
    public const string CODE = 'no_pending_price_change';

    public function __construct(string $message = 'Brak oczekującej zmiany ceny.')
    {
        parent::__construct($message);
    }
}
