<?php

namespace Ordering\Application\Order;

use SharedKernel\DomainException;

/**
 * Cena co najmniej jednej oferty różni się od tej z koszyka (H5). DLACZEGO
 * odmowa zamiast cichego przyjęcia nowej ceny: kupujący zgodził się na cenę,
 * którą widział; nowej musi świadomie potwierdzić.
 */
final class PriceChangedSinceCart extends DomainException
{
    public const string CODE = 'price_changed_since_cart';

    /**
     * @param  list<PriceChange>  $changes
     */
    public function __construct(public readonly array $changes)
    {
        parent::__construct('Ceny zmieniły się dla ofert: '.implode(', ', array_map(static fn (PriceChange $change): int => $change->offerId, $changes)).'.');
    }
}
