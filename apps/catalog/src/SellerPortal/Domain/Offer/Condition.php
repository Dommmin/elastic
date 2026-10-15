<?php

namespace SellerPortal\Domain\Offer;

/**
 * Stan egzemplarza (nowy / używany) — opis oferty, nie produktu z katalogu.
 */
enum Condition: string
{
    case New = 'new';
    case Used = 'used';
}
