<?php

namespace SellerPortal\Application;

use SharedKernel\DomainException;

/**
 * Użytkownik ma już konto sprzedawcy. DLACZEGO w aplikacji, a nie w agregacie: reguła dotyczy WSZYSTKICH sprzedawców, a pojedynczy agregat o innych nie wie (ostatnią gwarancją jest unikalny `sellers.user_id`).
 */
final class SellerAlreadyRegistered extends DomainException
{
    public const string CODE = 'seller_already_registered';

    public function __construct(string $message = 'To konto ma już zgłoszenie sprzedawcy.')
    {
        parent::__construct($message);
    }
}
