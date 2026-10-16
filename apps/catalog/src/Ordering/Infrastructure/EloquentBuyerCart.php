<?php

namespace Ordering\Infrastructure;

use App\Models\CartItem;
use Ordering\Application\Port\BuyerCart;
use Ordering\Domain\BuyerId;

/**
 * Koszyk w tabeli `cart_items`. Czyszczenie idzie przez zwykłe połączenie, więc
 * dołącza do transakcji handlera: zamówienia i pusty koszyk powstają razem (R9).
 */
final class EloquentBuyerCart implements BuyerCart
{
    public function clear(BuyerId $buyerId): void
    {
        CartItem::query()->where('user_id', $buyerId->value())->delete();
    }
}
