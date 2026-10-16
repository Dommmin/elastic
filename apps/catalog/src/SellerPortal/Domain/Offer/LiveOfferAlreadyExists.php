<?php

namespace SellerPortal\Domain\Offer;

use SharedKernel\DomainException;

/**
 * Sprzedawca ma już żywą ofertę na ten produkt. DLACZEGO nie w agregacie: reguła dotyczy WIELU ofert, więc pilnuje jej aplikacja (hasLiveOffer), a indeks unikalny jest ostatnią gwarancją.
 */
final class LiveOfferAlreadyExists extends DomainException
{
    public const string CODE = 'live_offer_exists';

    public function __construct(string $message = 'Masz już aktywną ofertę na ten produkt.')
    {
        parent::__construct($message);
    }
}
