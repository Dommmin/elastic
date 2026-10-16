<?php

use Ordering\Domain\Actor;
use Ordering\Domain\BuyerId;
use Ordering\Domain\Cancellation;
use Ordering\Domain\CancelledBy;
use Ordering\Domain\Event\OrderCancelled;
use Ordering\Domain\Event\OrderExpired;
use Ordering\Domain\Event\OrderShipped;
use Ordering\Domain\Event\RefundCompleted;
use Ordering\Domain\Event\RefundRequested;
use Ordering\Domain\InvalidOrderTransition;
use Ordering\Domain\LineStatus;
use Ordering\Domain\Order;
use Ordering\Domain\OrderAction;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderStatus;
use Ordering\Domain\Payment;
use Ordering\Domain\PaymentId;
use Ordering\Domain\Reason;
use Ordering\Domain\Refund;
use Ordering\Domain\RefundId;
use Ordering\Domain\RefundReason;
use Ordering\Domain\RefundStatus;
use Ordering\Domain\SellerId;
use Ordering\Domain\TrackingNumber;
use SharedKernel\Money;
use Tests\Unit\Ordering\Domain\OrderMother;

test('wygasa dopiero po terminie i 5 min karencji', function (bool $reserved) {
    $placedAt = new DateTimeImmutable('2026-10-16 14:59:00');
    $order = $reserved ? OrderMother::awaitingPayment($placedAt) : OrderMother::placed($placedAt);
    $status = $order->status();

    $tooEarly = new DateTimeImmutable('2026-10-16 15:18:59'); // 14:59 + 15 min + 4:59
    expect($order->isDueForExpiry($tooEarly))->toBeFalse();
    $order->expire($tooEarly);
    expect($order->status())->toBe($status)
        ->and($order->releaseEvents())->toBe([]);

    $due = new DateTimeImmutable('2026-10-16 15:19:00'); // + 5:00
    expect($order->isDueForExpiry($due))->toBeTrue();
    $order->expire($due);

    expect($order->status())->toBe(OrderStatus::Expired)
        ->and($order->isDueForExpiry($due))->toBeFalse();
    $events = $order->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(OrderExpired::class)
        ->and($events[0]->orderId->value())->toBe(7)
        ->and($events[0]->occurredAt())->toEqual($due);
})->with([
    'Placed' => [false],
    'AwaitingPayment' => [true],
]);

test('expire w Paid to no-op', function () {
    $order = OrderMother::paid();
    $later = OrderMother::now()->modify('+1 day');

    expect($order->isDueForExpiry($later))->toBeFalse();

    $order->expire($later);

    expect($order->status())->toBe(OrderStatus::Paid)
        ->and($order->releaseEvents())->toBe([]);
});

test('kupujący anuluje w Placed i AwaitingPayment, nie w Paid', function (OrderStatus $status) {
    $order = OrderMother::inStatus($status);
    $now = OrderMother::now()->modify('+2 minutes');

    $order->cancelByBuyer($now);

    expect($order->status())->toBe(OrderStatus::Cancelled)
        ->and($order->cancellation()->by)->toBe(CancelledBy::Buyer)
        ->and($order->cancellation()->reason)->toBeNull()
        ->and($order->refunds())->toBe([]);

    $events = $order->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(OrderCancelled::class)
        ->and($events[0]->by)->toBe(CancelledBy::Buyer)
        ->and($events[0]->reason)->toBeNull();

    expect(fn () => OrderMother::paid()->cancelByBuyer($now))->toThrow(InvalidOrderTransition::class);
})->with([OrderStatus::Placed, OrderStatus::AwaitingPayment]);

test('sprzedawca anuluje opłacone: Cancelled i zwrot cancelled_by_seller na całą wpłatę', function () {
    $order = OrderMother::paid();
    $now = OrderMother::now()->modify('+1 hour');

    $order->cancelBySeller(Reason::fromString('  Brak towaru  '), $now);

    expect($order->status())->toBe(OrderStatus::Cancelled)
        ->and($order->cancellation()->by)->toBe(CancelledBy::Seller)
        ->and($order->cancellation()->reason->value())->toBe('Brak towaru')
        ->and($order->payment()->paymentId->value())->toBe('pay-1')
        ->and($order->refunds())->toHaveCount(1)
        ->and($order->refunds()[0]->refundId->value())->toBe('refund-7-1')
        ->and($order->refunds()[0]->paymentId->value())->toBe('pay-1')
        ->and($order->refunds()[0]->amount->cents())->toBe(4498)
        ->and($order->refunds()[0]->reason)->toBe(RefundReason::CancelledBySeller);

    $events = $order->releaseEvents();
    expect(array_map(static fn (object $e): string => $e::class, $events))->toBe([OrderCancelled::class, RefundRequested::class])
        ->and($events[0]->by)->toBe(CancelledBy::Seller)
        ->and($events[0]->reason->value())->toBe('Brak towaru')
        ->and($events[1]->reason)->toBe(RefundReason::CancelledBySeller)
        ->and($events[1]->amount->cents())->toBe(4498);
});

test('sprzedawca anuluje tylko opłacone', function (OrderStatus $status) {
    $order = OrderMother::inStatus($status);

    expect(fn () => $order->cancelBySeller(Reason::fromString('Brak towaru'), OrderMother::now()))
        ->toThrow(InvalidOrderTransition::class);
})->with([OrderStatus::Placed, OrderStatus::AwaitingPayment, OrderStatus::Shipped, OrderStatus::Cancelled]);

test('wysyłka tylko z Paid', function (OrderStatus $status) {
    $order = OrderMother::inStatus($status);

    expect(fn () => $order->ship(TrackingNumber::fromString('PL123'), OrderMother::now()))
        ->toThrow(InvalidOrderTransition::class);

    $paid = OrderMother::paid();
    $now = OrderMother::now()->modify('+1 day');
    $paid->ship(TrackingNumber::fromString(' PL123456 '), $now);

    expect($paid->status())->toBe(OrderStatus::Shipped)
        ->and($paid->shipment()->trackingNumber->value())->toBe('PL123456')
        ->and($paid->shipment()->shippedAt)->toEqual($now);

    $events = $paid->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(OrderShipped::class)
        ->and($events[0]->trackingNumber->value())->toBe('PL123456');
})->with([OrderStatus::Placed, OrderStatus::AwaitingPayment, OrderStatus::Shipped, OrderStatus::Cancelled, OrderStatus::Expired]);

test('zwrot zakończony; drugi raz no-op', function () {
    $order = OrderMother::cancelledBySeller();
    $now = OrderMother::now()->modify('+2 hours');

    $order->markRefundCompleted(RefundId::fromString('refund-7-1'), $now);

    expect($order->refunds()[0]->status)->toBe(RefundStatus::Completed)
        ->and($order->refunds()[0]->completedAt)->toEqual($now)
        ->and($order->status())->toBe(OrderStatus::Cancelled);

    $events = $order->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(RefundCompleted::class)
        ->and($events[0]->refundId->value())->toBe('refund-7-1');

    $order->markRefundCompleted(RefundId::fromString('refund-7-1'), $now->modify('+1 hour'));

    expect($order->releaseEvents())->toBe([])
        ->and($order->refunds()[0]->completedAt)->toEqual($now);
});

test('zakończenie nieistniejącego zwrotu rzuca InvalidOrderTransition', function () {
    $order = OrderMother::paid();

    expect(fn () => $order->markRefundCompleted(RefundId::fromString('refund-7-1'), OrderMother::now()))
        ->toThrow(InvalidOrderTransition::class);
});

test('identyfikatory zwrotów są kolejne: refund-7-1, refund-7-2', function () {
    $order = OrderMother::paid();

    $order->recordPayment(PaymentId::fromString('pay-2'), Money::fromCents(4498), OrderMother::now()->modify('+6 minutes'));
    $order->cancelBySeller(Reason::fromString('Brak towaru'), OrderMother::now()->modify('+1 hour'));

    expect(array_map(static fn (Refund $refund): string => $refund->refundId->value(), $order->refunds()))
        ->toBe(['refund-7-1', 'refund-7-2'])
        ->and($order->refunds()[1]->paymentId->value())->toBe('pay-1');
});

test('allowedActionsFor dla kupującego i sprzedawcy w każdym statusie', function (OrderStatus $status, array $buyer, array $seller) {
    $order = OrderMother::inStatus($status);

    expect($order->allowedActionsFor(Actor::Buyer))->toBe($buyer)
        ->and($order->allowedActionsFor(Actor::Seller))->toBe($seller);
})->with([
    'Placed' => [OrderStatus::Placed, [OrderAction::Cancel], []],
    'AwaitingPayment' => [OrderStatus::AwaitingPayment, [OrderAction::Pay, OrderAction::Cancel], []],
    'Paid' => [OrderStatus::Paid, [], [OrderAction::Ship, OrderAction::CancelBySeller]],
    'Shipped' => [OrderStatus::Shipped, [], []],
    'Rejected' => [OrderStatus::Rejected, [], []],
    'Expired' => [OrderStatus::Expired, [], []],
    'Cancelled' => [OrderStatus::Cancelled, [], []],
]);

test('holdsReservations: zamówienie trzyma rezerwacje, dopóki z nich nie zrezygnowało', function (Closure $order, bool $holds) {
    expect($order()->holdsReservations())->toBe($holds);
})->with([
    'Placed (wciąż rezerwuje)' => [fn () => OrderMother::placed(), true],
    'AwaitingPayment' => [fn () => OrderMother::awaitingPayment(), true],
    'Paid' => [fn () => OrderMother::paid(), true],
    'Shipped' => [fn () => OrderMother::shipped(), true],
    'Cancelled przez sprzedawcę (H8)' => [fn () => OrderMother::cancelledBySeller(), true],
    'Cancelled przez kupującego' => [fn () => OrderMother::cancelledByBuyer(), false],
    'Rejected' => [fn () => OrderMother::rejected(), false],
    'Expired' => [fn () => OrderMother::expired(), false],
]);

test('requirePayable przepuszcza tylko AwaitingPayment', function (OrderStatus $status) {
    OrderMother::awaitingPayment()->requirePayable();

    expect(fn () => OrderMother::inStatus($status)->requirePayable())->toThrow(InvalidOrderTransition::class);
})->with([OrderStatus::Placed, OrderStatus::Paid, OrderStatus::Expired, OrderStatus::Cancelled]);

test('odtworzenie z bazy oddaje cały stan i nie nagrywa zdarzeń', function () {
    $placedAt = OrderMother::now();
    $line = OrderMother::line(1, 1999, 2)->reserved();
    $payment = new Payment(PaymentId::fromString('pay-1'), Money::fromCents(3998), $placedAt->modify('+5 minutes'));
    $refund = new Refund(RefundId::fromString('refund-7-1'), PaymentId::fromString('pay-1'), Money::fromCents(3998), RefundReason::CancelledBySeller, RefundStatus::Requested, $placedAt->modify('+1 hour'), null);
    $cancellation = new Cancellation(CancelledBy::Seller, Reason::fromString('Brak towaru'));

    $order = Order::reconstitute(
        OrderId::fromInt(7), BuyerId::fromInt(3), SellerId::fromInt(5), [$line], OrderStatus::Cancelled,
        $placedAt, $placedAt->modify('+15 minutes'), $payment, [$refund], null, $cancellation, null, 4,
    );

    expect($order->pendingEvents())->toBe([])
        ->and($order->status())->toBe(OrderStatus::Cancelled)
        ->and($order->lines()[0]->status())->toBe(LineStatus::Reserved)
        ->and($order->total()->cents())->toBe(3998)
        ->and($order->paymentDeadline())->toEqual($placedAt->modify('+15 minutes'))
        ->and($order->payment())->toBe($payment)
        ->and($order->refunds())->toBe([$refund])
        ->and($order->rejection())->toBeNull()
        ->and($order->cancellation())->toBe($cancellation)
        ->and($order->shipment())->toBeNull()
        ->and($order->version())->toBe(4);

    $order->markRefundCompleted(RefundId::fromString('refund-7-1'), $placedAt->modify('+2 hours'));
    expect($order->refunds()[0]->status)->toBe(RefundStatus::Completed);
});
