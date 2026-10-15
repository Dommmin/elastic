<?php

namespace SellerPortal\Domain\Offer;

/**
 * Dlaczego oferta jest zawieszona. DLACZEGO rozróżniamy: odblokowanie
 * sprzedawcy ma przywrócić WYŁĄCZNIE oferty zawieszone z powodu blokady
 * (`SellerBlocked`), a nie te, które moderator zawiesił ręcznie.
 */
enum SuspensionKind: string
{
    case Moderation = 'moderation';
    case SellerBlocked = 'seller_blocked';
}
