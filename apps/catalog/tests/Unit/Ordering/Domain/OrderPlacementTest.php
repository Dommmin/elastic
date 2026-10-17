<?php

use Ordering\Domain\BuyerId;
use Ordering\Domain\Event\OrderPlaced;
use Ordering\Domain\InvalidOrderLines;
use Ordering\Domain\LineStatus;
use Ordering\Domain\Order;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderLine;
use Ordering\Domain\OrderStatus;
use Ordering\Domain\SellerId;
use SharedKernel\Currency;
use Tests\Unit\Ordering\Domain\OrderMother;

test('złożenie: status Placed, termin za 15 min, suma pozycji', function () {
    $now = OrderMother::now();

    $order = Order::place(OrderId::fromInt(7), BuyerId::fromInt(3), SellerId::fromInt(5), OrderMother::defaultLines(), $now);

    expect($order->status())->toBe(OrderStatus::Placed)
        ->and($order->id()->value())->toBe(7)
        ->and($order->buyerId()->value())->toBe(3)
        ->and($order->sellerId()->value())->toBe(5)
        ->and($order->placedAt())->toEqual($now)
        ->and($order->paymentDeadline())->toEqual(new DateTimeImmutable('2026-10-16 12:15:00'))
        ->and($order->total()->cents())->toBe(4498)
        ->and($order->total()->currency())->toBe(Currency::PLN)
        ->and($order->lines())->toHaveCount(2)
        ->and(array_map(static fn (OrderLine $line): LineStatus => $line->status(), $order->lines()))->toBe([LineStatus::Pending, LineStatus::Pending])
        ->and($order->payment())->toBeNull()
        ->and($order->refunds())->toBe([])
        ->and($order->rejection())->toBeNull()
        ->and($order->cancellation())->toBeNull()
        ->and($order->shipment())->toBeNull()
        ->and($order->version())->toBe(0);

    $events = $order->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(OrderPlaced::class)
        ->and($events[0]->orderId->value())->toBe(7)
        ->and($events[0]->buyerId->value())->toBe(3)
        ->and($events[0]->sellerId->value())->toBe(5)
        ->and($events[0]->total->cents())->toBe(4498)
        ->and($events[0]->occurredAt())->toEqual($now);
});

test('zamówienie bez pozycji, z 21 pozycjami, z powtórzoną ofertą albo dwiema walutami rzuca InvalidOrderLines', function (Closure $lines) {
    expect(fn () => Order::place(OrderId::fromInt(7), BuyerId::fromInt(3), SellerId::fromInt(5), $lines(), OrderMother::now()))
        ->toThrow(InvalidOrderLines::class);
})->with([
    'bez pozycji' => [fn () => []],
    '21 pozycji' => [fn () => array_map(static fn (int $i): OrderLine => OrderMother::line($i, 100, 1), range(1, 21))],
    'powtórzona oferta' => [fn () => [OrderMother::line(1, 100, 1), OrderMother::line(1, 100, 2)]],
    'dwie waluty' => [fn () => [OrderMother::line(1, 100, 1), OrderMother::line(2, 100, 1, Currency::EUR)]],
]);

test('20 pozycji to jeszcze poprawne zamówienie', function () {
    $lines = array_map(static fn (int $i): OrderLine => OrderMother::line($i, 100, 1), range(1, Order::MAX_LINES));

    $order = Order::place(OrderId::fromInt(7), BuyerId::fromInt(3), SellerId::fromInt(5), $lines, OrderMother::now());

    expect($order->lines())->toHaveCount(20)
        ->and($order->total()->cents())->toBe(2000);
});

test('InvalidOrderLines ma stabilny kod reguły', function () {
    expect(InvalidOrderLines::CODE)->toBe('invalid_order_lines');
});
