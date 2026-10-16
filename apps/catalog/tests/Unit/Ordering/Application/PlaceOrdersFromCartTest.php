<?php

use Ordering\Application\Order\CannotBuyOwnOffer;
use Ordering\Application\Order\CartEmpty;
use Ordering\Application\Order\CartLine;
use Ordering\Application\Order\OfferNoLongerAvailable;
use Ordering\Application\Order\PriceChange;
use Ordering\Application\Order\PriceChangedSinceCart;
use Ordering\Domain\BuyerId;
use Ordering\Domain\Event\OrderPlaced;
use Ordering\Domain\OfferId;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderNotFound;
use Ordering\Domain\OrderStatus;
use Tests\Unit\Ordering\InMemory\OrderingWorld;

beforeEach(function (): void {
    $this->world = new OrderingWorld;
    // Sprzedawca 20 (użytkownik 200) ma oferty 1 i 2, sprzedawca 10 (użytkownik 100) ofertę 3.
    $this->world->offer(1, sellerId: 20, sellerUserId: 200, priceCents: 1999);
    $this->world->offer(2, sellerId: 20, sellerUserId: 200, priceCents: 500);
    $this->world->offer(3, sellerId: 10, sellerUserId: 100, priceCents: 2500);
});

test('koszyk z ofertami dwóch sprzedawców daje dwa zamówienia i czyści koszyk', function (): void {
    $ids = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [
        new CartLine(1, 2, 1999),
        new CartLine(3, 1, 2500),
        new CartLine(2, 1, 500),
    ]);

    expect($ids)->toHaveCount(2);

    // Kolejność: rosnące sellerId, pozycje w kolejności koszyka.
    $first = $this->world->orders->get($ids[0]);
    $second = $this->world->orders->get($ids[1]);

    expect($first->sellerId()->value())->toBe(10)
        ->and(array_map(fn ($line) => $line->offerId()->value(), $first->lines()))->toBe([3])
        ->and($second->sellerId()->value())->toBe(20)
        ->and(array_map(fn ($line) => $line->offerId()->value(), $second->lines()))->toBe([1, 2])
        ->and($second->total()->cents())->toBe(2 * 1999 + 500)
        ->and($second->lines()[0]->productName()->value())->toBe('Produkt 1')
        ->and($first->status())->toBe(OrderStatus::Placed)
        ->and($first->buyerId()->value())->toBe(OrderingWorld::BUYER_USER_ID)
        ->and($first->placedAt())->toEqual($this->world->clock->now)
        // Czyści tylko złożone oferty: pozycja dodana w innej karcie zostaje.
        ->and($this->world->cart->cleared)->toEqual([[
            'buyerId' => BuyerId::fromInt(OrderingWorld::BUYER_USER_ID),
            'offerIds' => [OfferId::fromInt(1), OfferId::fromInt(3), OfferId::fromInt(2)],
        ]])
        ->and($this->world->transactions->runs)->toBe(1);
});

test('zmieniona cena: żadne zamówienie, wyjątek z listą różnic', function (): void {
    $this->world->offer(1, sellerId: 20, sellerUserId: 200, priceCents: 2499);

    try {
        $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1999), new CartLine(3, 1, 2500)]);
        $this->fail('Oczekiwano PriceChangedSinceCart');
    } catch (PriceChangedSinceCart $e) {
        expect($e->changes)->toEqual([new PriceChange(1, 1999, 2499)])
            ->and($e::CODE)->toBe('price_changed_since_cart');
    }

    expect(fn () => $this->world->orders->get(OrderId::fromInt(1)))->toThrow(OrderNotFound::class)
        ->and($this->world->cart->cleared)->toBe([])
        ->and($this->world->dispatcher->dispatched)->toBe([]);
});

test('niewidoczna oferta: żadne zamówienie, wyjątek z listą ofert', function (): void {
    $this->world->offer(2, sellerId: 20, sellerUserId: 200, priceCents: 500, listed: false);

    try {
        // Oferta 9 w ogóle nie istnieje (brak snapshotu), oferta 2 jest wycofana.
        $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1999), new CartLine(2, 1, 500), new CartLine(9, 1, 100)]);
        $this->fail('Oczekiwano OfferNoLongerAvailable');
    } catch (OfferNoLongerAvailable $e) {
        expect($e->offerIds)->toBe([2, 9])
            ->and($e::CODE)->toBe('offer_no_longer_available');
    }

    expect(fn () => $this->world->orders->get(OrderId::fromInt(1)))->toThrow(OrderNotFound::class)
        ->and($this->world->cart->cleared)->toBe([]);
});

test('kupowanie własnej oferty rzuca CannotBuyOwnOffer', function (): void {
    expect(fn () => $this->world->placeOrders(200, [new CartLine(3, 1, 2500), new CartLine(1, 1, 1999)]))
        ->toThrow(CannotBuyOwnOffer::class);

    expect(CannotBuyOwnOffer::CODE)->toBe('cannot_buy_own_offer')
        ->and(fn () => $this->world->orders->get(OrderId::fromInt(1)))->toThrow(OrderNotFound::class);
});

test('pusty koszyk rzuca CartEmpty', function (): void {
    expect(fn () => $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, []))->toThrow(CartEmpty::class);

    expect(CartEmpty::CODE)->toBe('cart_empty')
        ->and($this->world->transactions->runs)->toBe(0);
});

test('dostępność nie jest sprawdzana przy składaniu', function (): void {
    // Oferta widoczna, ale bez sztuk: rezerwacja odmówi dopiero w sadze.
    $this->world->reservations->stock(1, 0);

    $ids = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1999)]);

    expect($ids)->toHaveCount(1)
        ->and($this->world->orders->get($ids[0])->status())->toBe(OrderStatus::Placed)
        ->and($this->world->reservations->calls)->toBe([]);
});

test('złożenie dispatchuje OrderPlaced dla każdego zamówienia', function (): void {
    $ids = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1999), new CartLine(3, 1, 2500)]);

    expect($this->world->dispatcher->classes())->toBe([OrderPlaced::class, OrderPlaced::class])
        ->and(array_map(fn (OrderPlaced $event) => $event->orderId, $this->world->dispatcher->dispatched))->toEqual($ids);
});
