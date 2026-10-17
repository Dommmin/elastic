<?php

namespace SellerPortal\Domain\Offer;

use SharedKernel\DomainException;

/**
 * Publikacja oferty ze stanem 0. DLACZEGO reguła w agregacie: oferta, której nie da się kupić, nie powinna pojawić się w sprzedaży.
 */
final class OfferCannotBePublishedWithoutStock extends DomainException
{
    public const string CODE = 'offer_without_stock';

    public function __construct(string $message = 'Nie można opublikować oferty bez stanu magazynowego.')
    {
        parent::__construct($message);
    }
}
