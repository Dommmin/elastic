<?php

use Ordering\Application\Order\CancelOrderByBuyer;
use Ordering\Application\Order\CartLine;
use Ordering\Application\Order\ExpireOverdueOrders;
use Ordering\Application\Order\ShipOrder;
use Ordering\Application\Order\StartPayment;
use Ordering\Domain\Event\OrderShipped;
use Ordering\Domain\InvalidOrderTransition;
use Ordering\Domain\OrderNotFound;
use Ordering\Domain\OrderStatus;
use SharedKernel\ConcurrencyConflict;
use Tests\Unit\Ordering\InMemory\OrderingWorld;

beforeEach(function (): void {
    $this->world = new OrderingWorld;
    $this->world->offer(1, sellerId: 20, sellerUserId: 200, priceCents: 1999);
});

test('StartPayment poza AwaitingPayment rzuca InvalidOrderTransition', function (): void {
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1999)]);

    expect(fn () => $this->world->startPayment()->handle(new StartPayment(OrderingWorld::BUYER_USER_ID, $orderId->value(), 'https://shop.test/orders/1')))
        ->toThrow(InvalidOrderTransition::class);

    expect($this->world->gateway->startedPayments)->toBe([]);
});

test('StartPayment przekazuje termin zamówienia jako wygaśnięcie sesji', function (): void {
    $orderId = $this->world->awaitingPaymentOrder();
    $versionBefore = $this->world->orders->get($orderId)->version();
    $this->world->clock->advance('PT3M');

    $url = $this->world->startPayment()->handle(new StartPayment(OrderingWorld::BUYER_USER_ID, $orderId->value(), 'https://shop.test/orders/1'));

    $order = $this->world->orders->get($orderId);
    $started = $this->world->gateway->startedPayments[0];

    expect($url)->toBe('https://gateway.test/pay/1')
        ->and($started['orderId'])->toEqual($orderId)
        ->and($started['amount']->cents())->toBe(1999)
        ->and($started['expiresAt'])->toEqual($order->paymentDeadline())
        ->and($started['returnUrl'])->toBe('https://shop.test/orders/1')
        // Sesja płatności niczego nie zapisuje w zamówieniu.
        ->and($order->version())->toBe($versionBefore);
});

test('kupujący nie widzi cudzego zamówienia', function (): void {
    $orderId = $this->world->awaitingPaymentOrder();
    $stranger = 4;

    expect(fn () => $this->world->startPayment()->handle(new StartPayment($stranger, $orderId->value(), 'https://shop.test/orders/1')))
        ->toThrow(OrderNotFound::class)
        ->and(fn () => $this->world->cancelByBuyer()->handle(new CancelOrderByBuyer($stranger, $orderId->value())))
        ->toThrow(OrderNotFound::class);

    expect($this->world->orders->get($orderId)->status())->toBe(OrderStatus::AwaitingPayment);
});

test('sprzedawca nie wyśle cudzego zamówienia', function (): void {
    $orderId = $this->world->paidOrder();
    // Użytkownik 300 to inny sprzedawca, użytkownik 400 nie jest sprzedawcą wcale.
    $this->world->sellers->register(300, 30);

    expect(fn () => $this->world->ship()->handle(new ShipOrder(300, $orderId->value(), 'PL123456')))
        ->toThrow(OrderNotFound::class)
        ->and(fn () => $this->world->ship()->handle(new ShipOrder(400, $orderId->value(), 'PL123456')))
        ->toThrow(OrderNotFound::class);

    expect($this->world->orders->get($orderId)->status())->toBe(OrderStatus::Paid);

    $this->world->forgetDispatched();
    $this->world->ship()->handle(new ShipOrder(200, $orderId->value(), 'PL123456'));

    expect($this->world->orders->get($orderId)->status())->toBe(OrderStatus::Shipped)
        ->and($this->world->dispatcher->classes())->toBe([OrderShipped::class]);
});

test('handler zapisujący ponawia przy ConcurrencyConflict do 3 prób', function (): void {
    $orderId = $this->world->paidOrder();

    $this->world->orders->failNextSaves(2);
    $this->world->ship()->handle(new ShipOrder(200, $orderId->value(), 'PL123456'));

    expect($this->world->orders->get($orderId)->status())->toBe(OrderStatus::Shipped);

    $other = $this->world->awaitingPaymentOrder();
    $this->world->orders->failNextSaves(3);

    expect(fn () => $this->world->cancelByBuyer()->handle(new CancelOrderByBuyer(OrderingWorld::BUYER_USER_ID, $other->value())))
        ->toThrow(ConcurrencyConflict::class);
});

test('ExpireOverdueOrders wygasza tylko po karencji i pomija konflikty', function (): void {
    $first = $this->world->awaitingPaymentOrder();
    [$stuckInPlaced] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1999)]);
    $this->world->clock->advance('PT10M');
    $later = $this->world->awaitingPaymentOrder();

    // Termin pierwszych dwóch minął 5 minut temu (koniec karencji), trzeciego jeszcze nie.
    $this->world->clock->advance('PT10M');
    $this->world->orders->failNextSaves(1);

    $expired = $this->world->expireOverdue()->handle(new ExpireOverdueOrders);

    expect($expired)->toBe(1)
        ->and($this->world->orders->get($first)->status())->toBe(OrderStatus::AwaitingPayment)
        ->and($this->world->orders->get($stuckInPlaced)->status())->toBe(OrderStatus::Expired)
        ->and($this->world->orders->get($later)->status())->toBe(OrderStatus::AwaitingPayment);

    // Pominięte zamówienie łapie następny przebieg sweepu.
    expect($this->world->expireOverdue()->handle(new ExpireOverdueOrders))->toBe(1)
        ->and($this->world->orders->get($first)->status())->toBe(OrderStatus::Expired)
        ->and($this->world->orders->get($later)->status())->toBe(OrderStatus::AwaitingPayment);

    $this->world->clock->advance('PT1M');

    expect($this->world->expireOverdue()->handle(new ExpireOverdueOrders))->toBe(0);

    $this->world->clock->advance('PT9M');

    expect($this->world->expireOverdue()->handle(new ExpireOverdueOrders))->toBe(1)
        ->and($this->world->orders->get($later)->status())->toBe(OrderStatus::Expired);
});

test('ExpireOverdueOrders przechodzi przez wszystkie paczki', function (): void {
    for ($i = 0; $i < 101; $i++) {
        $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1999)]);
    }

    $this->world->clock->advance('PT20M');

    expect($this->world->expireOverdue()->handle(new ExpireOverdueOrders))->toBe(101);
});
