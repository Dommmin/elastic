<?php

namespace SellerPortal\Domain\Offer;

use SharedKernel\DomainException;

/**
 * Rezerwacja oferty, która nie jest opublikowana. DLACZEGO: wycofana albo zawieszona oferta dotrzymuje istniejących rezerwacji (H4), ale nowych nie przyjmuje.
 */
final class OfferNotReservable extends DomainException
{
    public const string CODE = 'offer_not_reservable';

    public function __construct(string $message = 'Ta oferta nie przyjmuje rezerwacji.')
    {
        parent::__construct($message);
    }
}
