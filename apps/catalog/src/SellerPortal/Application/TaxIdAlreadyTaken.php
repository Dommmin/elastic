<?php

namespace SellerPortal\Application;

use SharedKernel\DomainException;

/**
 * NIP jest już przypisany do innego sprzedawcy. DLACZEGO w aplikacji, a nie w agregacie: reguła dotyczy WSZYSTKICH sprzedawców (ostatnią gwarancją jest unikalny indeks `sellers.nip`).
 */
final class TaxIdAlreadyTaken extends DomainException
{
    public const string CODE = 'tax_id_already_taken';

    public function __construct(string $message = 'Ten NIP jest już zarejestrowany.')
    {
        parent::__construct($message);
    }
}
