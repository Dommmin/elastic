<?php

namespace SellerPortal\Domain\Seller;

/**
 * Stan konta sprzedawcy w cyklu moderacji.
 *
 * DLACZEGO enum, a nie flagi `approved`/`blocked`: dwie flagi dopuszczają
 * kombinacje bez sensu (zatwierdzony i odrzucony naraz). Jeden stan z
 * jawną listą przejść w `Seller` czyni takie kombinacje niemożliwymi.
 * `Rejected` jest końcowy — odrzucony sprzedawca składa nowe zgłoszenie.
 */
enum SellerStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Rejected = 'rejected';
    case Blocked = 'blocked';
}
