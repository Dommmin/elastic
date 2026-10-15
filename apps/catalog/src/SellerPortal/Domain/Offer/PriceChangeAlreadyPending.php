<?php

namespace SellerPortal\Domain\Offer;

use SellerPortal\Domain\Shared\DomainException;

/**
 * Przy oczekującej zmianie ceny nie przyjmujemy kolejnej.
 *
 * DLACZEGO: moderator ocenia JEDNĄ propozycję. Dwie naraz oznaczałyby pytanie "którą akceptujesz?"; sprzedawca musi najpierw ją wycofać albo poczekać na decyzję.
 */
final class PriceChangeAlreadyPending extends DomainException
{
    public const string CODE = 'price_change_pending';

    public function __construct(string $message = 'Zmiana ceny czeka już na akceptację moderatora.')
    {
        parent::__construct($message);
    }
}
