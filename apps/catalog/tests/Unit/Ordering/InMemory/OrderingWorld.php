<?php

namespace Tests\Unit\Ordering\InMemory;

use Ordering\Application\Order\CancelOrderByBuyerHandler;
use Ordering\Application\Order\CancelOrderBySellerHandler;
use Ordering\Application\Order\CartLine;
use Ordering\Application\Order\ExpireOverdueOrdersHandler;
use Ordering\Application\Order\OrderAccess;
use Ordering\Application\Order\PlaceOrdersFromCart;
use Ordering\Application\Order\PlaceOrdersFromCartHandler;
use Ordering\Application\Order\RecordPayment;
use Ordering\Application\Order\RecordPaymentFailureHandler;
use Ordering\Application\Order\RecordPaymentHandler;
use Ordering\Application\Order\ShipOrderHandler;
use Ordering\Application\Order\StartPaymentHandler;
use Ordering\Application\Port\OfferSnapshot;
use Ordering\Domain\OfferId;
use Ordering\Domain\OrderId;
use Ordering\Domain\ProductName;
use Ordering\Domain\SellerId;
use SharedKernel\Money;

/**
 * Złożony w pamięci kontekst Ordering do testów handlerów i sagi: wszystkie
 * porty to dublety, a każdy handler dostaje te same instancje.
 * Domyślnie kupuje użytkownik `BUYER_USER_ID`.
 */
final class OrderingWorld
{
    public const int BUYER_USER_ID = 3;

    public InMemoryOrderRepository $orders;

    public FixedClock $clock;

    public ImmediateTransactionManager $transactions;

    public RecordingDispatcher $dispatcher;

    public FakeOfferSnapshots $offers;

    public FakeStockReservations $reservations;

    public FakePaymentGateway $gateway;

    public FakeProcessJobQueue $jobs;

    public FakeBuyerCart $cart;

    public FakeSellerAccounts $sellers;

    public OrderAccess $access;

    public function __construct()
    {
        $this->orders = new InMemoryOrderRepository;
        $this->clock = new FixedClock;
        $this->transactions = new ImmediateTransactionManager;
        $this->dispatcher = new RecordingDispatcher;
        $this->offers = new FakeOfferSnapshots;
        $this->reservations = new FakeStockReservations;
        $this->gateway = new FakePaymentGateway;
        $this->jobs = new FakeProcessJobQueue;
        $this->cart = new FakeBuyerCart;
        $this->sellers = new FakeSellerAccounts;
        $this->access = new OrderAccess($this->orders, $this->sellers);
    }

    /**
     * Oferta widoczna w snapshotach; rejestruje też konto sprzedawcy
     * (użytkownik → sprzedawca), żeby działał `OrderAccess::forSeller`.
     * Ponowne wywołanie dla tej samej oferty podmienia ją (zmiana ceny, wycofanie).
     */
    public function offer(int $offerId, int $sellerId, int $sellerUserId, int $priceCents, bool $listed = true): void
    {
        $this->sellers->register($sellerUserId, $sellerId);
        $this->offers->put(new OfferSnapshot(
            OfferId::fromInt($offerId),
            SellerId::fromInt($sellerId),
            $sellerUserId,
            ProductName::fromString("Produkt {$offerId}"),
            Money::fromCents($priceCents),
            $listed,
        ));
    }

    /**
     * @param  list<CartLine>  $lines
     * @return list<OrderId>
     */
    public function placeOrders(int $buyerUserId, array $lines): array
    {
        return $this->placeOrdersHandler()->handle(new PlaceOrdersFromCart($buyerUserId, $lines));
    }

    /**
     * Zamówienie na ofertę 1 (musi istnieć), doprowadzone do `AwaitingPayment`
     * tak, jak zrobiłaby to saga: każda pozycja oznaczona jako zarezerwowana.
     * Bez portu rezerwacji, bo sagę testuje osobny zestaw.
     */
    public function awaitingPaymentOrder(int $buyerUserId = self::BUYER_USER_ID, int $offerId = 1): OrderId
    {
        $snapshot = $this->offers->forOffers([OfferId::fromInt($offerId)])[$offerId];
        [$orderId] = $this->placeOrders($buyerUserId, [new CartLine($offerId, 1, $snapshot->price->cents())]);
        $this->markAllLinesReserved($orderId);

        return $orderId;
    }

    public function paidOrder(int $buyerUserId = self::BUYER_USER_ID, int $offerId = 1, string $paymentId = 'pay-1'): OrderId
    {
        $orderId = $this->awaitingPaymentOrder($buyerUserId, $offerId);
        $total = $this->orders->get($orderId)->total();
        $this->recordPayment()->handle(new RecordPayment($orderId->value(), $paymentId, $total->cents(), $total->currency()->value));

        return $orderId;
    }

    public function markAllLinesReserved(OrderId $orderId): void
    {
        $order = $this->orders->get($orderId);

        foreach ($order->lines() as $line) {
            $order->markLineReserved($line->offerId(), $this->clock->now());
        }

        $this->orders->save($order);
        $this->dispatcher->dispatch($order->releaseEvents());
    }

    public function placeOrdersHandler(): PlaceOrdersFromCartHandler
    {
        return new PlaceOrdersFromCartHandler($this->transactions, $this->clock, $this->dispatcher, $this->orders, $this->offers, $this->cart);
    }

    public function startPayment(): StartPaymentHandler
    {
        return new StartPaymentHandler($this->access, $this->gateway);
    }

    public function recordPayment(): RecordPaymentHandler
    {
        return new RecordPaymentHandler($this->transactions, $this->clock, $this->dispatcher, $this->orders);
    }

    public function recordPaymentFailure(): RecordPaymentFailureHandler
    {
        return new RecordPaymentFailureHandler($this->transactions, $this->clock, $this->dispatcher, $this->orders);
    }

    public function cancelByBuyer(): CancelOrderByBuyerHandler
    {
        return new CancelOrderByBuyerHandler($this->transactions, $this->clock, $this->dispatcher, $this->access, $this->orders);
    }

    public function cancelBySeller(): CancelOrderBySellerHandler
    {
        return new CancelOrderBySellerHandler($this->transactions, $this->clock, $this->dispatcher, $this->access, $this->orders);
    }

    public function ship(): ShipOrderHandler
    {
        return new ShipOrderHandler($this->transactions, $this->clock, $this->dispatcher, $this->access, $this->orders);
    }

    public function expireOverdue(): ExpireOverdueOrdersHandler
    {
        return new ExpireOverdueOrdersHandler($this->transactions, $this->clock, $this->dispatcher, $this->orders);
    }

    /**
     * Czyści zapis zdarzeń, żeby test widział tylko to, co zrobił handler pod testem.
     */
    public function forgetDispatched(): void
    {
        $this->dispatcher->dispatched = [];
    }
}
