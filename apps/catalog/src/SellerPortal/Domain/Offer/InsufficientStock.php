<?php

namespace SellerPortal\Domain\Offer;

use SharedKernel\DomainException;

/**
 * Rezerwacja większa niż dostępne sztuki. DLACZEGO w agregacie: dostępne = stan − wszystkie rezerwacje, więc tylko oferta widzi całość i chroni przed overbookingiem.
 */
final class InsufficientStock extends DomainException
{
    public const string CODE = 'insufficient_stock';

    public function __construct(string $message = 'Brak wystarczającej liczby dostępnych sztuk.')
    {
        parent::__construct($message);
    }
}
