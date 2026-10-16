<?php

namespace Ordering\Domain;

/**
 * Kto anulował. DLACZEGO to ważne: anulowanie kupującego zwalnia rezerwacje, sprzedawcy nie (H8) i zleca zwrot.
 */
enum CancelledBy: string
{
    case Buyer = 'buyer';
    case Seller = 'seller';
}
