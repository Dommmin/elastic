<?php

namespace SellerPortal\Domain\Seller;

/**
 * Stan konta sprzedawcy w cyklu moderacji.
 *
 * DLACZEGO enum, a nie flagi `approved`/`blocked`: dwie flagi dopuszczają
 * kombinacje bez sensu (zatwierdzony i odrzucony naraz). Jeden stan z
 * jawną listą przejść w `Seller` czyni takie kombinacje niemożliwymi.
 * `Rejected` jest końcowy: z odrzucenia nie ma przejścia, a użytkownik ma
 * najwyżej jeden profil sprzedawcy, więc odrzucone zgłoszenie jest ostateczne.
 */
enum SellerStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Rejected = 'rejected';
    case Blocked = 'blocked';
}
