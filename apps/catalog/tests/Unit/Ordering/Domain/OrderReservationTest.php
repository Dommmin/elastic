<?php

use Ordering\Domain\Event\OrderAwaitingPayment;
use Ordering\Domain\Event\OrderLineReserved;
use Ordering\Domain\Event\OrderRejected;
use Ordering\Domain\InvalidOrderLines;
use Ordering\Domain\InvalidOrderTransition;
use Ordering\Domain\LineStatus;
use Ordering\Domain\OfferId;
use Ordering\Domain\OrderLine;
use Ordering\Domain\OrderStatus;
use Ordering\Domain\RejectionReason;
use Tests\Unit\Ordering\Domain\OrderMother;

test('wszystkie pozycje zarezerwowane → AwaitingPayment', function () {
    $order = OrderMother::placed();
    $now = OrderMother::now()->modify('+10 seconds');

    $order->markLineReserved(OfferId::fromInt(1), $now);

    expect($order->status())->toBe(OrderStatus::Placed)
        ->and(array_map(static fn (OrderLine $line): LineStatus => $line->status(), $order->lines()))->toBe([LineStatus::Reserved, LineStatus::Pending]);

    $order->markLineReserved(OfferId::fromInt(2), $now);

    expect($order->status())->toBe(OrderStatus::AwaitingPayment)
        ->and(array_map(static fn (OrderLine $line): LineStatus => $line->status(), $order->lines()))->toBe([LineStatus::Reserved, LineStatus::Reserved]);

    $events = $order->releaseEvents();
    expect(array_map(static fn (object $e): string => $e::class, $events))->toBe([OrderLineReserved::class, OrderLineReserved::class, OrderAwaitingPayment::class])
        ->and($events[0]->offerId->value())->toBe(1)
        ->and($events[1]->offerId->value())->toBe(2)
        ->and($events[2]->orderId->value())->toBe(7)
        ->and($events[2]->deadline)->toEqual(new DateTimeImmutable('2026-10-16 12:15:00'))
        ->and($events[2]->occurredAt())->toEqual($now);
});

test('ponowne markLineReserved to no-op', function () {
    $order = OrderMother::placed();
    $order->markLineReserved(OfferId::fromInt(1), OrderMother::now());
    $order->releaseEvents();

    $order->markLineReserved(OfferId::fromInt(1), OrderMother::now());

    expect($order->releaseEvents())->toBe([])
        ->and($order->status())->toBe(OrderStatus::Placed);
});

test('markLineReserved poza Placed rzuca InvalidOrderTransition', function (OrderStatus $status) {
    $order = OrderMother::inStatus($status);

    expect(fn () => $order->markLineReserved(OfferId::fromInt(1), OrderMother::now()))
        ->toThrow(InvalidOrderTransition::class);
})->with([OrderStatus::AwaitingPayment, OrderStatus::Paid, OrderStatus::Rejected, OrderStatus::Expired, OrderStatus::Cancelled]);

test('markLineReserved dla oferty spoza zamówienia rzuca InvalidOrderLines', function () {
    $order = OrderMother::placed();

    expect(fn () => $order->markLineReserved(OfferId::fromInt(99), OrderMother::now()))
        ->toThrow(InvalidOrderLines::class);
});

test('odrzucenie zapisuje powód i ofertę', function () {
    $order = OrderMother::placed();
    $order->markLineReserved(OfferId::fromInt(1), OrderMother::now());
    $order->releaseEvents();

    $order->reject(RejectionReason::PriceChanged, OfferId::fromInt(2), OrderMother::now());

    expect($order->status())->toBe(OrderStatus::Rejected)
        ->and($order->rejection()->reason)->toBe(RejectionReason::PriceChanged)
        ->and($order->rejection()->offerId->value())->toBe(2);

    $events = $order->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(OrderRejected::class)
        ->and($events[0]->reason)->toBe(RejectionReason::PriceChanged)
        ->and($events[0]->offerId->value())->toBe(2);
});

test('odrzucenie poza Placed rzuca InvalidOrderTransition', function (OrderStatus $status) {
    $order = OrderMother::inStatus($status);

    expect(fn () => $order->reject(RejectionReason::OutOfStock, OfferId::fromInt(1), OrderMother::now()))
        ->toThrow(InvalidOrderTransition::class);
})->with([OrderStatus::AwaitingPayment, OrderStatus::Rejected, OrderStatus::Cancelled]);
