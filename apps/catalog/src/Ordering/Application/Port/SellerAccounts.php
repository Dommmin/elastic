<?php

namespace Ordering\Application\Port;

use Ordering\Domain\SellerId;

/**
 * Port: który sprzedawca należy do zalogowanego użytkownika. DLACZEGO port,
 * a nie kolumna w zamówieniu: konto sprzedawcy żyje w portalu, a zamówienie
 * zna tylko `SellerId`.
 */
interface SellerAccounts
{
    public function sellerIdForUser(int $userId): ?SellerId;
}
