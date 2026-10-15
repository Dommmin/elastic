<?php

namespace SellerPortal\Domain\Offer;

use SellerPortal\Domain\Shared\DomainException;

/**
 * Akcja niedozwolona w bieżącym statusie oferty (np. zmiana stanu wycofanej).
 *
 * DLACZEGO wyjątek, a nie ciche zignorowanie: podwójne kliknięcie albo
 * wyścig sprzedawcy z moderatorem muszą być widoczne, a nie "jakoś zadziałać".
 */
final class InvalidOfferTransition extends DomainException
{
    public const string CODE = 'invalid_offer_transition';

    public static function from(OfferStatus $status, OfferAction $action): self
    {
        return new self("Akcja „{$action->value}” jest niedozwolona dla oferty w stanie „{$status->value}”.");
    }
}
