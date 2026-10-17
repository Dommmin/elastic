<?php

namespace Ordering\Infrastructure;

use App\Models\CartItem;
use Ordering\Application\Port\BuyerCart;
use Ordering\Domain\BuyerId;
use Ordering\Domain\OfferId;

/**
 * Koszyk w tabeli `cart_items`. Czyszczenie idzie przez zwykłe połączenie, więc
 * dołącza do transakcji handlera: zamówienia i pusty koszyk powstają razem (R9).
 */
final class EloquentBuyerCart implements BuyerCart
{
    public function clear(BuyerId $buyerId, array $offerIds): void
    {
        CartItem::query()
            ->where('user_id', $buyerId->value())
            ->whereIn('offer_id', array_map(static fn (OfferId $offerId): int => $offerId->value(), $offerIds))
            ->delete();
    }
}
