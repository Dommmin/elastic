<?php

namespace SellerPortal\Domain\Offer;

/**
 * Kto pyta o dozwolone akcje. Ta sama oferta oferuje sprzedawcy i
 * moderatorowi różne ruchy — patrz `Offer::allowedActionsFor`.
 */
enum Actor
{
    case Seller;
    case Moderator;
}
