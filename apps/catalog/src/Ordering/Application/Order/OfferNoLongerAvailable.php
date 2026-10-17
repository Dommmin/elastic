<?php

namespace Ordering\Application\Order;

use SharedKernel\DomainException;

/**
 * Część ofert z koszyka zniknęła albo przestała być widoczna. Wyjątek niesie
 * pełną listę, żeby UI oznaczyło wszystkie pozycje naraz, a nie po jednej
 * na każdą próbę.
 */
final class OfferNoLongerAvailable extends DomainException
{
    public const string CODE = 'offer_no_longer_available';

    /**
     * @param  list<int>  $offerIds
     */
    public function __construct(public readonly array $offerIds)
    {
        parent::__construct('Oferty niedostępne: '.implode(', ', $offerIds).'.');
    }
}
