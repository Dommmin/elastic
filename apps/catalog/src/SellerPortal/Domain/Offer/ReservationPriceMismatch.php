<?php

namespace SellerPortal\Domain\Offer;

use SharedKernel\DomainException;

/**
 * Kupujący rezerwuje po innej cenie niż obecna. DLACZEGO: zamówienie zamraża cenę, którą kupujący widział (H5); jeśli w międzyczasie się zmieniła, nie wolno sprzedać po starej.
 */
final class ReservationPriceMismatch extends DomainException
{
    public const string CODE = 'reservation_price_mismatch';

    public function __construct(string $message = 'Cena oferty zmieniła się od złożenia zamówienia.')
    {
        parent::__construct($message);
    }
}
