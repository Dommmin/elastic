<?php

namespace SellerPortal\Domain\Offer;

use SharedKernel\DomainException;

/**
 * Potwierdzenie rezerwacji, której oferta nie trzyma. DLACZEGO nie cichy no-op: „zwolniona, potem potwierdzona” oznaczałoby sprzedaż bez zmniejszenia stanu.
 */
final class ReservationNotFound extends DomainException
{
    public const string CODE = 'reservation_not_found';

    public function __construct(string $message = 'Nie ma aktywnej rezerwacji o tym kluczu.')
    {
        parent::__construct($message);
    }
}
