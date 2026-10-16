<?php

namespace Ordering\Infrastructure;

use Illuminate\Support\Facades\DB;
use Ordering\Application\Port\SellerAccounts;
use Ordering\Domain\SellerId;

/**
 * Mapowanie użytkownik → sprzedawca czytane wprost z `sellers.user_id`:
 * konto sprzedawcy należy do portalu, a Ordering potrzebuje tylko jego ID.
 */
final class DbSellerAccounts implements SellerAccounts
{
    public function sellerIdForUser(int $userId): ?SellerId
    {
        $id = DB::table('sellers')->where('user_id', $userId)->value('id');

        return $id === null ? null : SellerId::fromInt((int) $id);
    }
}
