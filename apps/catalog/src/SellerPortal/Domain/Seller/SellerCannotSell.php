<?php

namespace SellerPortal\Domain\Seller;

use SharedKernel\DomainException;

/**
 * Sprzedawca, który nie jest aktywny (oczekuje, odrzucony, zablokowany),
 * próbuje wystawiać oferty. Reguła należy do Seller (`canSell`), bo to on zna swój stan.
 */
final class SellerCannotSell extends DomainException
{
    public const string CODE = 'seller_cannot_sell';

    public function __construct(string $message = 'Tylko aktywny sprzedawca może wystawiać oferty.')
    {
        parent::__construct($message);
    }
}
