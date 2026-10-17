<?php

namespace SellerPortal\Domain\Seller;

use SharedKernel\DomainException;

/**
 * Próba przejścia, którego maszyna stanów sprzedawcy nie dopuszcza
 * (np. odblokowanie kogoś, kto nie jest zablokowany).
 *
 * DLACZEGO wyjątek, a nie ciche zignorowanie: podwójne kliknięcie
 * moderatora lub wyścig dwóch kart musi być widoczny, a nie "jakoś zadziałać".
 */
final class InvalidSellerTransition extends DomainException
{
    public const string CODE = 'invalid_seller_transition';

    public static function from(SellerStatus $status, string $action): self
    {
        return new self("Akcja „{$action}” jest niedozwolona dla sprzedawcy w stanie „{$status->value}”.");
    }
}
