<?php

namespace Ordering\Application\Order;

use SharedKernel\DomainException;

/**
 * Sprzedawca próbuje kupić własną ofertę (H12). DLACZEGO w aplikacji, a nie
 * w `Order`: agregat zna tylko `BuyerId` i `SellerId`, a to, że oba należą
 * do tego samego człowieka, wie dopiero port `OfferSnapshots`.
 */
final class CannotBuyOwnOffer extends DomainException
{
    public const string CODE = 'cannot_buy_own_offer';

    /**
     * @param  list<int>  $offerIds
     */
    public function __construct(public readonly array $offerIds)
    {
        parent::__construct('Nie można kupić własnej oferty: '.implode(', ', $offerIds).'.');
    }
}
