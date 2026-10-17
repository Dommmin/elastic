<?php

namespace Ordering\Domain;

/**
 * Dlaczego rezerwacja odmówiła (tłumaczenie `ReservationResult` portalu na język zamówień).
 */
enum RejectionReason: string
{
    case OutOfStock = 'out_of_stock';
    case PriceChanged = 'price_changed';
    case OfferUnavailable = 'offer_unavailable';
}
