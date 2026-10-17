<?php

namespace SellerPortal\Domain\Offer;

/**
 * Los rezerwacji zapisany w bazie. DLACZEGO potrzebny, skoro agregat zna tylko aktywne: ponowione potwierdzenie musi odróżnić „już potwierdzona” (no-op) od „zwolniona albo nigdy nie istniała” (błąd). Idempotencja wymaga pamięci.
 */
enum ReservationStatus: string
{
    case Active = 'active';
    case Confirmed = 'confirmed';
    case Released = 'released';
}
