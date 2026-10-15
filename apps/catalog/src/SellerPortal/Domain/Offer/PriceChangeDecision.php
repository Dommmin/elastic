<?php

namespace SellerPortal\Domain\Offer;

/**
 * Wynik oceny zmiany ceny: obowiązuje od razu albo czeka na moderatora.
 */
enum PriceChangeDecision
{
    case AppliedNow;
    case AwaitingApproval;
}
