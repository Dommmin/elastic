<?php

use Ordering\Application\Order\CancelOrderByBuyer;
use Ordering\Application\Order\CancelOrderBySeller;
use Ordering\Application\Order\CartLine;
use Ordering\Application\Order\ExpireOverdueOrders;
use Ordering\Application\Port\ReservationResult;
use Ordering\Application\Process\OrderReservationKey;
use Ordering\Domain\Event\OrderPaid;
use Ordering\Domain\Event\RefundRequested;
use Ordering\Domain\OrderStatus;
use Ordering\Domain\RefundReason;
use Ordering\Domain\RefundStatus;
use Ordering\Domain\RejectionReason;
use SharedKernel\ConcurrencyConflict;
use Tests\Unit\Ordering\InMemory\OrderingWorld;

// Wyścigi ze specu (sekcja 5). Każdy test układa przeplot kroków ręcznie:
// hak `afterReserve` wstrzykuje cudzy krok między T1 a T2, a kolejka sagi
// wykonuje się dopiero, gdy test wywoła `runQueuedJobs` albo krok wprost.

beforeEach(function (): void {
    $this->world = new OrderingWorld;
    $this->world->offer(1, sellerId: 20, sellerUserId: 200, priceCents: 1000);
    $this->world->offer(2, sellerId: 20, sellerUserId: 200, priceCents: 500);
});

test('R1: anulowanie w trakcie rezerwacji nie zostawia rezerwacji', function (): void {
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000), new CartLine(2, 1, 500)]);
    $this->world->jobs->take();

    // T1 pierwszej pozycji zajęło sztukę; kupujący anuluje, zanim saga wróci do zamówienia (T2).
    $this->world->reservations->afterReserve(function () use ($orderId): void {
        $this->world->cancelByBuyer()->handle(new CancelOrderByBuyer(OrderingWorld::BUYER_USER_ID, $orderId->value()));
    });

    $this->world->reserveOrderLines()->run($orderId);

    // Zwolnienie po anulowaniu jeszcze nie ruszyło (jest w kolejce), a sztuki już nie ma: zwolniła ją gałąź R1.
    expect($this->world->orders->get($orderId)->status())->toBe(OrderStatus::Cancelled)
        ->and($this->world->jobs->names())->toBe(['releaseReservations'])
        ->and($this->world->reservations->active)->toBe([])
        ->and(array_column($this->world->reservations->calls, 'offerId'))->not->toContain(2);
});

test('R1: zdublowany krok rezerwacji nie zwalnia sztuk zamówienia, które czeka na płatność', function (): void {
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000)]);
    $this->world->jobs->take();

    // Drugi worker dostał ten sam job (retry_after) i doprowadził zamówienie do AwaitingPayment między T1 a T2 pierwszego.
    $this->world->reservations->afterReserve(function () use ($orderId): void {
        $this->world->reserveOrderLines()->run($orderId);
    });

    $this->world->reserveOrderLines()->run($orderId);

    expect($this->world->orders->get($orderId)->status())->toBe(OrderStatus::AwaitingPayment)
        ->and($this->world->reservations->isActive(OrderReservationKey::for($orderId), 1))->toBeTrue()
        ->and($this->world->reservations->newReservations)->toBe(1);
});

test('R2: ponowiony krok po awarii między T1 a T2 rezerwuje raz', function (): void {
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000), new CartLine(2, 2, 500)]);
    $this->world->jobs->take();
    $this->world->orders->failNextSaves(3);

    expect(fn () => $this->world->reserveOrderLines()->run($orderId))->toThrow(ConcurrencyConflict::class);
    expect($this->world->reservations->newReservations)->toBe(1)
        ->and($this->world->orders->get($orderId)->status())->toBe(OrderStatus::Placed);

    $this->world->reserveOrderLines()->run($orderId);

    $key = OrderReservationKey::for($orderId);
    expect($this->world->orders->get($orderId)->status())->toBe(OrderStatus::AwaitingPayment)
        ->and($this->world->reservations->newReservations)->toBe(2)
        ->and($this->world->reservations->active)->toHaveCount(2)
        ->and($this->world->reservations->isActive($key, 1))->toBeTrue()
        ->and($this->world->reservations->isActive($key, 2))->toBeTrue();
});

test('R2: konflikt wersji w T2 ponawia T2 na świeżym stanie bez ponawiania joba', function (): void {
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000)]);
    $this->world->jobs->take();
    $this->world->orders->failNextSaves(2);

    $this->world->reserveOrderLines()->run($orderId);

    expect($this->world->orders->get($orderId)->status())->toBe(OrderStatus::AwaitingPayment)
        ->and($this->world->reservations->newReservations)->toBe(1);
});

test('R3: płatność i wygaśnięcie w tym samym momencie: zawsze jeden wynik', function (): void {
    [$expiredFirst] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000)]);
    [$paidFirst] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(2, 1, 500)]);
    $this->world->runQueuedJobs();
    $this->world->clock->advance('PT20M');

    // Porządek „zapłata → wygaśnięcie”: webhook zapisał pierwszy, więc sweep już tego zamówienia nie wygasza.
    $this->world->pay($paidFirst, 'pay-b');
    $this->world->expireOverdue()->handle(new ExpireOverdueOrders);

    // Porządek „wygaśnięcie → zapłata”: sweep zapisał pierwszy, webhook na świeżym stanie zleca zwrot.
    $this->world->pay($expiredFirst, 'pay-a');
    $this->world->runQueuedJobs();

    $expired = $this->world->orders->get($expiredFirst);
    $paid = $this->world->orders->get($paidFirst);

    expect($expired->status())->toBe(OrderStatus::Expired)
        ->and($expired->payment())->toBeNull()
        ->and($expired->refunds()[0]->reason)->toBe(RefundReason::LatePayment)
        ->and($expired->refunds()[0]->status)->toBe(RefundStatus::Completed)
        ->and($paid->status())->toBe(OrderStatus::Paid)
        ->and($paid->refunds())->toBe([])
        ->and($this->world->reservations->calls)->toContain(['op' => 'confirm', 'key' => OrderReservationKey::for($paidFirst), 'offerId' => 2])
        ->and($this->world->reservations->active)->toBe([]);
});

test('R3: przegrany zapis webhooka ponawia się po wygaśnięciu i kończy zwrotem', function (): void {
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000)]);
    $this->world->runQueuedJobs();
    $this->world->clock->advance('PT20M');

    // Webhook wczytał AwaitingPayment, sweep zapisał Expired pierwszy: zapis webhooka dostaje konflikt.
    $this->world->expireOverdue()->handle(new ExpireOverdueOrders);
    $this->world->orders->failNextSaves(1);
    $this->world->pay($orderId, 'pay-1');
    $this->world->runQueuedJobs();

    $order = $this->world->orders->get($orderId);
    expect($order->status())->toBe(OrderStatus::Expired)
        ->and($order->refunds())->toHaveCount(1)
        ->and($order->refunds()[0]->status)->toBe(RefundStatus::Completed)
        ->and($this->world->reservations->active)->toBe([]);
});

test('R4: zdublowany webhook nie robi nic', function (): void {
    $this->world->reservations->stock(1, 3);
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000)]);
    $this->world->runQueuedJobs();
    $this->world->pay($orderId, 'pay-1');
    $this->world->runQueuedJobs();
    $this->world->forgetDispatched();

    $this->world->pay($orderId, 'pay-1');

    expect($this->world->dispatcher->dispatched)->toBe([])
        ->and($this->world->jobs->jobs)->toBe([])
        ->and($this->world->reservations->stockOf(1))->toBe(2)
        ->and($this->world->gateway->refundAttempts)->toBe(0);
});

test('R6: dwóch kupujących, ostatnia sztuka: jeden AwaitingPayment, drugi Rejected', function (): void {
    $this->world->reservations->stock(1, 1);
    [$first] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000)]);
    [$second] = $this->world->placeOrders(4, [new CartLine(1, 1, 1000)]);

    $this->world->runQueuedJobs();

    $rejected = $this->world->orders->get($second);
    expect($this->world->orders->get($first)->status())->toBe(OrderStatus::AwaitingPayment)
        ->and($rejected->status())->toBe(OrderStatus::Rejected)
        ->and($rejected->rejection()->reason)->toBe(RejectionReason::OutOfStock)
        ->and($this->world->reservations->active)->toHaveCount(1)
        ->and($this->world->reservations->isActive(OrderReservationKey::for($first), 1))->toBeTrue();
});

test('R7: anulowanie przez sprzedawcę przed potwierdzeniem: potwierdzenie i tak się wykonuje', function (): void {
    $this->world->reservations->stock(1, 5);
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 2, 1000)]);
    $this->world->runQueuedJobs();
    $this->world->pay($orderId, 'pay-1');

    // Krok potwierdzenia czeka w kolejce, a sprzedawca już anuluje.
    $this->world->cancelBySeller()->handle(new CancelOrderBySeller(200, $orderId->value(), 'Brak towaru'));
    expect($this->world->jobs->names())->toBe(['confirmReservations', 'refund']);

    $this->world->runQueuedJobs();

    $order = $this->world->orders->get($orderId);
    expect($order->status())->toBe(OrderStatus::Cancelled)
        ->and($this->world->reservations->stockOf(1))->toBe(3)
        ->and($this->world->reservations->active)->toBe([])
        ->and(array_column($this->world->reservations->calls, 'op'))->not->toContain('release')
        ->and($order->refunds()[0]->reason)->toBe(RefundReason::CancelledBySeller)
        ->and($order->refunds()[0]->status)->toBe(RefundStatus::Completed);
});

test('R8: oferta wycofana przed rezerwacją: Rejected z offer_unavailable', function (): void {
    [$earlier] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000)]);
    $this->world->runQueuedJobs();
    [$later] = $this->world->placeOrders(4, [new CartLine(1, 1, 1000)]);

    // Sprzedawca wycofał ofertę między złożeniem a krokiem rezerwacji.
    $this->world->reservations->script(1, ReservationResult::OfferUnavailable);
    $this->world->runQueuedJobs();

    $rejected = $this->world->orders->get($later);
    expect($rejected->status())->toBe(OrderStatus::Rejected)
        ->and($rejected->rejection()->reason)->toBe(RejectionReason::OfferUnavailable);

    // Wcześniejsza rezerwacja jest dotrzymana (H4): płatność zamienia ją w sprzedaż.
    $this->world->pay($earlier, 'pay-1');
    $this->world->runQueuedJobs();

    expect($this->world->orders->get($earlier)->status())->toBe(OrderStatus::Paid)
        ->and($this->world->reservations->calls)->toContain(['op' => 'confirm', 'key' => OrderReservationKey::for($earlier), 'offerId' => 1])
        ->and($this->world->reservations->active)->toBe([]);
});

test('R10: zamówienie utknięte w Placed wygasa i zwalnia rezerwacje', function (): void {
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000), new CartLine(2, 1, 500)]);
    $this->world->jobs->take();

    // Worker padł po T1 pierwszej pozycji (pozycja w zamówieniu wciąż Pending), job przepadł.
    $this->world->orders->failNextSaves(3);
    expect(fn () => $this->world->reserveOrderLines()->run($orderId))->toThrow(ConcurrencyConflict::class);
    expect($this->world->reservations->active)->toHaveCount(1);

    $this->world->clock->advance('PT20M');
    $this->world->expireOverdue()->handle(new ExpireOverdueOrders);
    $this->world->runQueuedJobs();

    expect($this->world->orders->get($orderId)->status())->toBe(OrderStatus::Expired)
        ->and($this->world->reservations->active)->toBe([]);
});

test('zdarzenia płatności trafiają do kolejki sagi jako kroki', function (): void {
    [$orderId] = $this->world->placeOrders(OrderingWorld::BUYER_USER_ID, [new CartLine(1, 1, 1000)]);
    $this->world->runQueuedJobs();
    $this->world->pay($orderId, 'pay-1');
    $this->world->pay($orderId, 'pay-2');

    expect($this->world->dispatcher->classes())->toContain(OrderPaid::class, RefundRequested::class)
        ->and($this->world->jobs->names())->toBe(['confirmReservations', 'refund'])
        ->and($this->world->jobs->jobs[1]['refundId']->value())->toBe('refund-'.$orderId->value().'-1');
});
