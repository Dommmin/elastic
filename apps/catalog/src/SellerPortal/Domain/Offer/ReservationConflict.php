<?php

namespace SellerPortal\Domain\Offer;

use SharedKernel\DomainException;

/**
 * Ten sam klucz rezerwacji z inną ilością. DLACZEGO błąd, a nie nadpisanie: ponowienie ma być identyczne; inna ilość pod tym samym kluczem to pomyłka klienta rezerwacji.
 */
final class ReservationConflict extends DomainException
{
    public const string CODE = 'reservation_conflict';

    public function __construct(string $message = 'Rezerwacja o tym kluczu istnieje z inną liczbą sztuk.')
    {
        parent::__construct($message);
    }
}
