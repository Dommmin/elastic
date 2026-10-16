<?php

namespace Ordering\Application\Port;

use Ordering\Domain\OfferId;

/**
 * Port odczytu ofert z portalu sprzedawcy (adapter w Infrastructure tłumaczy model portalu).
 */
interface OfferSnapshots
{
    /**
     * Brakująca oferta to brak klucza, nie wyjątek: składanie zbiera
     * wszystkie brakujące naraz, żeby UI oznaczyło każdą pozycję.
     *
     * @param  list<OfferId>  $offerIds
     * @return array<int, OfferSnapshot> klucz = ID oferty
     */
    public function forOffers(array $offerIds): array;
}
