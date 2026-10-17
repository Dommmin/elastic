<?php

namespace Ordering\Application\Order;

use Ordering\Application\Port\SellerAccounts;
use Ordering\Domain\BuyerId;
use Ordering\Domain\Order;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderNotFound;
use Ordering\Domain\OrderRepository;

/**
 * Kto może dotknąć którego zamówienia: wspólny krok handlerów kupującego i sprzedawcy.
 *
 * DLACZEGO cudze zamówienie to `OrderNotFound`, a nie osobny „brak dostępu”:
 * UI pokazuje 404, żeby nie zdradzać, że zamówienie o takim numerze istnieje
 * (numery są kolejne, łatwo je zgadywać).
 * DLACZEGO sprzedawca przychodzi jako ID użytkownika: konto sprzedawcy żyje
 * w portalu; użytkownik bez konta sprzedawcy nie ma żadnych zamówień.
 */
final readonly class OrderAccess
{
    public function __construct(
        private OrderRepository $orders,
        private SellerAccounts $sellers,
    ) {}

    /**
     * @throws OrderNotFound
     */
    public function forBuyer(BuyerId $buyerId, OrderId $orderId): Order
    {
        $order = $this->orders->get($orderId);

        if (! $order->buyerId()->equals($buyerId)) {
            throw new OrderNotFound;
        }

        return $order;
    }

    /**
     * @throws OrderNotFound
     */
    public function forSeller(int $sellerUserId, OrderId $orderId): Order
    {
        $sellerId = $this->sellers->sellerIdForUser($sellerUserId) ?? throw new OrderNotFound;
        $order = $this->orders->get($orderId);

        if (! $order->sellerId()->equals($sellerId)) {
            throw new OrderNotFound;
        }

        return $order;
    }
}
