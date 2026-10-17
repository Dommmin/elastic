<?php

namespace Ordering\Application\Port;

/**
 * Wynik rezerwacji w języku Orderingu. DLACZEGO enum zamiast wyjątków
 * portalu: odmowa rezerwacji to zwykły wynik biznesowy (zamówienie
 * `Rejected`), a adapter (ACL) tłumaczy na niego wyjątki domeny portalu.
 */
enum ReservationResult
{
    case Reserved;
    case OutOfStock;
    case PriceChanged;
    case OfferUnavailable;
}
