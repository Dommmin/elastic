<?php

use Ordering\Application\Order\CancelOrderByBuyer;
use Ordering\Application\Order\CancelOrderBySeller;
use Ordering\Application\Order\CartLine;
use Ordering\Application\Order\ExpireOverdueOrders;
use Ordering\Application\Port\PaymentGatewayUnavailable;
use Ordering\Application\Port\ReservationResult;
use Ordering\Application\Process\OrderReservationKey;
use Ordering\Domain\CancelledBy;
use Ordering\Domain\OfferId;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderStatus;
use Ordering\Domain\RefundReason;
use Ordering\Domain\RefundStatus;
use Ordering\Domain\RejectionReason;
use Tests\Unit\Ordering\InMemory\OrderingWorld;

// Saga zamówienia na dubletach: słuchacze process managera są podpięte do
// dispatchera świata, a `runQueuedJobs` wykonuje zlecone kroki do skutku.

beforeEach(function (): void {
    $this->world = new OrderingWorld;
    $this->world->offer(1, sellerId: 20, sellerUserId: 200, priceCents: 1000);
    $this->world->offer(2, sellerId: 20, sellerUserId: 200, priceCents: 500);
});

test('klucz rezerwacji zamówienia to order-{id}', function (): void {
    expect(OrderReservationKey::for(OrderId::fromInt(42)))->toBe('order-42');
});

test('szczęśliwa ścieżka: złożone → zarezerwowane → opłacone → potwierdzone', function (): void {
    $this->world->reservations->stock(1, 5);

    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 2, 1000)]);
    expect($this->world->jobs->names())->toBe(['reserveLines']);

    $this->world->runQueuedJobs();

    $key = OrderReservationKey::for($orderId);
    expect($this->world->orders->get($orderId)->status())->toBe(OrderStatus::AwaitingPayment)
        ->and($this->world->reservations->isActive($key, 1))->toBeTrue()
        ->and($this->world->reservations->stockOf(1))->toBe(5);

    $this->world->pay($orderId, 'pay-1');
    expect($this->world->orders->get($orderId)->status())->toBe(OrderStatus::Paid)
        ->and($this->world->jobs->names())->toBe(['confirmReservations']);

    $this->world->runQueuedJobs();

    expect($this->world->reservations->stockOf(1))->toBe(3)
        ->and($this->world->reservations->active)->toBe([]);
});

test('brak towaru na drugiej pozycji: odrzucone, pierwsza zwolniona', function (): void {
    $this->world->reservations->stock(2, 0);

    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000), new CartLine(2, 1, 500)]);
    $this->world->runQueuedJobs();

    $order = $this->world->orders->get($orderId);
    $key = OrderReservationKey::for($orderId);

    expect($order->status())->toBe(OrderStatus::Rejected)
        ->and($order->rejection()->reason)->toBe(RejectionReason::OutOfStock)
        ->and($order->rejection()->offerId)->toEqual(OfferId::fromInt(2))
        ->and($this->world->reservations->active)->toBe([])
        ->and($this->world->reservations->calls)->toContain(['op' => 'release', 'key' => $key, 'offerId' => 1]);
});

test('zmieniona cena przy rezerwacji: odrzucone z powodem price_changed', function (): void {
    $this->world->reservations->script(1, ReservationResult::PriceChanged);

    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000)]);
    $this->world->runQueuedJobs();

    $order = $this->world->orders->get($orderId);

    expect($order->status())->toBe(OrderStatus::Rejected)
        ->and($order->rejection()->reason)->toBe(RejectionReason::PriceChanged)
        ->and($order->rejection()->reason->value)->toBe('price_changed');
});

test('wygaśnięcie zwalnia wszystkie pozycje', function (): void {
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000), new CartLine(2, 3, 500)]);
    $this->world->runQueuedJobs();
    expect($this->world->reservations->active)->toHaveCount(2);

    $this->world->clock->advance('PT20M');
    $this->world->expireOverdue()->handle(new ExpireOverdueOrders);
    $this->world->runQueuedJobs();

    expect($this->world->orders->get($orderId)->status())->toBe(OrderStatus::Expired)
        ->and($this->world->reservations->active)->toBe([]);
});

test('anulowanie przez kupującego zwalnia, przez sprzedawcę nie', function (): void {
    [$byBuyer] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000)]);
    $this->world->runQueuedJobs();

    $this->world->cancelByBuyer()->handle(new CancelOrderByBuyer(OrderingWorld::BUYER_USER_ID, $byBuyer->value()));
    expect($this->world->jobs->names())->toBe(['releaseReservations']);
    $this->world->runQueuedJobs();

    expect($this->world->reservations->isActive(OrderReservationKey::for($byBuyer), 1))->toBeFalse();

    $this->world->reservations->stock(2, 4);
    [$bySeller] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(2, 1, 500)]);
    $this->world->runQueuedJobs();
    $this->world->pay($bySeller, 'pay-2');
    $this->world->runQueuedJobs();
    $this->world->reservations->calls = [];

    $this->world->cancelBySeller()->handle(new CancelOrderBySeller(200, $bySeller->value(), 'Towar uszkodzony'));
    expect($this->world->jobs->names())->toBe(['refund']);
    $this->world->runQueuedJobs();

    $order = $this->world->orders->get($bySeller);
    expect($order->cancellation()->by)->toBe(CancelledBy::Seller)
        ->and($this->world->reservations->calls)->toBe([])
        ->and($this->world->reservations->stockOf(2))->toBe(3)
        ->and($order->refunds()[0]->status)->toBe(RefundStatus::Completed);
});

test('wpłata po terminie zleca zwrot, a zwrot się kończy', function (): void {
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000)]);
    $this->world->runQueuedJobs();
    $this->world->clock->advance('PT20M');
    $this->world->expireOverdue()->handle(new ExpireOverdueOrders);
    $this->world->runQueuedJobs();

    $this->world->pay($orderId, 'pay-late');
    expect($this->world->jobs->names())->toBe(['refund']);
    $this->world->runQueuedJobs();

    $order = $this->world->orders->get($orderId);
    $refund = $order->refunds()[0];

    expect($order->status())->toBe(OrderStatus::Expired)
        ->and($refund->reason)->toBe(RefundReason::LatePayment)
        ->and($refund->status)->toBe(RefundStatus::Completed)
        ->and($this->world->gateway->refunds)->toHaveCount(1)
        ->and($this->world->gateway->refunds[0]['refundId'])->toEqual($refund->refundId)
        ->and($this->world->reservations->calls)->not->toContain(['op' => 'confirm', 'key' => OrderReservationKey::for($orderId), 'offerId' => 1]);
});

test('nieudany zwrot zostaje Requested, ponowienie kończy', function (): void {
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000)]);
    $this->world->runQueuedJobs();
    $this->world->clock->advance('PT20M');
    $this->world->expireOverdue()->handle(new ExpireOverdueOrders);
    $this->world->runQueuedJobs();
    $this->world->pay($orderId, 'pay-late');
    $this->world->gateway->failNextRefunds(1);

    expect(fn () => $this->world->runQueuedJobs())->toThrow(PaymentGatewayUnavailable::class);
    expect($this->world->orders->get($orderId)->refunds()[0]->status)->toBe(RefundStatus::Requested)
        ->and($this->world->jobs->names())->toBe(['refund']);

    $this->world->runQueuedJobs();

    expect($this->world->orders->get($orderId)->refunds()[0]->status)->toBe(RefundStatus::Completed)
        ->and($this->world->gateway->refundAttempts)->toBe(2)
        ->and($this->world->gateway->refunds)->toHaveCount(1);
});

test('ponowiony zwrot po zakończeniu nie woła bramki drugi raz', function (): void {
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000)]);
    $this->world->runQueuedJobs();
    $this->world->pay($orderId, 'pay-1');
    $this->world->runQueuedJobs();
    $this->world->cancelBySeller()->handle(new CancelOrderBySeller(200, $orderId->value(), 'Brak towaru'));
    [$job] = $this->world->jobs->jobs;
    $this->world->runQueuedJobs();

    $this->world->refundPayment()->run($job['orderId'], $job['refundId']);

    expect($this->world->gateway->refundAttempts)->toBe(1);
});

test('potwierdzenie rusza tylko zamówienie z zaksięgowaną wpłatą', function (): void {
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000)]);
    $this->world->runQueuedJobs();

    $this->world->confirmOrderReservations()->run($orderId);

    expect($this->world->reservations->calls)->not->toContain(['op' => 'confirm', 'key' => OrderReservationKey::for($orderId), 'offerId' => 1])
        ->and($this->world->reservations->isActive(OrderReservationKey::for($orderId), 1))->toBeTrue();
});

test('rezerwacja ponowiona dla zamówienia, które już nie jest Placed, nic nie robi', function (): void {
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000)]);
    $this->world->runQueuedJobs();
    $this->world->reservations->calls = [];

    $this->world->reserveOrderLines()->run($orderId);

    expect($this->world->reservations->calls)->toBe([])
        ->and($this->world->orders->get($orderId)->status())->toBe(OrderStatus::AwaitingPayment);
});
