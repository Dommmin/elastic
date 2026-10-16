<?php

namespace SellerPortal\Domain\Offer;

use SharedKernel\DomainException;

/**
 * Sprzedawca ustawia stan poniżej zarezerwowanych sztuk. DLACZEGO zakaz: te sztuki są obiecane komuś, kto właśnie płaci (H3).
 */
final class StockBelowReserved extends DomainException
{
    public const string CODE = 'stock_below_reserved';

    public function __construct(string $message = 'Nie możesz zejść poniżej liczby zarezerwowanych sztuk.')
    {
        parent::__construct($message);
    }
}
