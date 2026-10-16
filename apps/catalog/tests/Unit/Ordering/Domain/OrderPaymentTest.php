<?php

use Ordering\Domain\Event\LatePaymentReceived;
use Ordering\Domain\Event\OrderPaid;
use Ordering\Domain\Event\OrderPaymentFailed;
use Ordering\Domain\Event\RefundRequested;
use Ordering\Domain\Order;
use Ordering\Domain\OrderStatus;
use Ordering\Domain\PaymentId;
use Ordering\Domain\RefundReason;
use Ordering\Domain\RefundStatus;
use SharedKernel\Currency;
use SharedKernel\Money;
use Tests\Unit\Ordering\Domain\OrderMother;

test('właściwa kwota w AwaitingPayment → Paid', function () {
    $order = OrderMother::awaitingPayment();
    $now = OrderMother::now()->modify('+5 minutes');

    $order->recordPayment(PaymentId::fromString('pay-1'), Money::fromCents(4498), $now);

    expect($order->status())->toBe(OrderStatus::Paid)
        ->and($order->payment()->paymentId->value())->toBe('pay-1')
        ->and($order->payment()->amount->cents())->toBe(4498)
        ->and($order->payment()->paidAt)->toEqual($now)
        ->and($order->refunds())->toBe([]);

    $events = $order->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(OrderPaid::class)
        ->and($events[0]->paymentId->value())->toBe('pay-1')
        ->and($events[0]->amount->cents())->toBe(4498)
        ->and($events[0]->occurredAt())->toEqual($now);
});

test('ten sam paymentId drugi raz to no-op', function (Closure $order, OrderStatus $status) {
    /** @var Order $order */
    $order = $order();
    $refundsBefore = $order->refunds();

    $order->recordPayment(PaymentId::fromString('pay-1'), OrderMother::total(), OrderMother::now()->modify('+2 days'));

    expect($order->releaseEvents())->toBe([])
        ->and($order->status())->toBe($status)
        ->and($order->refunds())->toEqual($refundsBefore)
        ->and($order->payment()->paymentId->value())->toBe('pay-1');
})->with([
    'Paid' => [fn () => OrderMother::paid(), OrderStatus::Paid],
    'Shipped' => [fn () => OrderMother::shipped(), OrderStatus::Shipped],
    'Cancelled (przez sprzedawcę)' => [fn () => OrderMother::cancelledBySeller(), OrderStatus::Cancelled],
]);

test('druga wpłata innym paymentId → zwrot duplicate_payment', function () {
    $order = OrderMother::paid();
    $now = OrderMother::now()->modify('+6 minutes');

    $order->recordPayment(PaymentId::fromString('pay-2'), Money::fromCents(4498), $now);

    expect($order->status())->toBe(OrderStatus::Paid)
        ->and($order->payment()->paymentId->value())->toBe('pay-1')
        ->and($order->refunds())->toHaveCount(1)
        ->and($order->refunds()[0]->refundId->value())->toBe('refund-7-1')
        ->and($order->refunds()[0]->paymentId->value())->toBe('pay-2')
        ->and($order->refunds()[0]->amount->cents())->toBe(4498)
        ->and($order->refunds()[0]->reason)->toBe(RefundReason::DuplicatePayment)
        ->and($order->refunds()[0]->status)->toBe(RefundStatus::Requested)
        ->and($order->refunds()[0]->requestedAt)->toEqual($now)
        ->and($order->refunds()[0]->completedAt)->toBeNull();

    $events = $order->releaseEvents();
    expect(array_map(static fn (object $e): string => $e::class, $events))->toBe([LatePaymentReceived::class, RefundRequested::class])
        ->and($events[0]->paymentId->value())->toBe('pay-2')
        ->and($events[0]->amount->cents())->toBe(4498)
        ->and($events[1]->refundId->value())->toBe('refund-7-1')
        ->and($events[1]->paymentId->value())->toBe('pay-2')
        ->and($events[1]->amount->cents())->toBe(4498)
        ->and($events[1]->reason)->toBe(RefundReason::DuplicatePayment);
});

test('wpłata w Expired, Cancelled, Rejected, Placed → LatePaymentReceived i zwrot late_payment', function (OrderStatus $status) {
    $order = OrderMother::inStatus($status);

    $order->recordPayment(PaymentId::fromString('pay-9'), Money::fromCents(4498), OrderMother::now()->modify('+30 minutes'));

    expect($order->status())->toBe($status)
        ->and($order->payment())->toBeNull()
        ->and($order->refunds())->toHaveCount(1)
        ->and($order->refunds()[0]->paymentId->value())->toBe('pay-9')
        ->and($order->refunds()[0]->amount->cents())->toBe(4498)
        ->and($order->refunds()[0]->reason)->toBe(RefundReason::LatePayment)
        ->and(OrderMother::eventClasses($order))->toBe([LatePaymentReceived::class, RefundRequested::class]);
})->with([OrderStatus::Expired, OrderStatus::Cancelled, OrderStatus::Rejected, OrderStatus::Placed]);

test('zła kwota → zwrot amount_mismatch, status bez zmian', function (Money $amount) {
    $order = OrderMother::awaitingPayment();

    $order->recordPayment(PaymentId::fromString('pay-1'), $amount, OrderMother::now()->modify('+5 minutes'));

    expect($order->status())->toBe(OrderStatus::AwaitingPayment)
        ->and($order->payment())->toBeNull()
        ->and($order->refunds())->toHaveCount(1)
        ->and($order->refunds()[0]->amount)->toEqual($amount)
        ->and($order->refunds()[0]->reason)->toBe(RefundReason::AmountMismatch)
        ->and(OrderMother::eventClasses($order))->toBe([LatePaymentReceived::class, RefundRequested::class]);
})->with([
    'o grosz za mało' => [fn () => Money::fromCents(4497)],
    'o grosz za dużo' => [fn () => Money::fromCents(4499)],
    'inna waluta' => [fn () => Money::fromCents(4498, Currency::EUR)],
]);

test('wpłata, która ma już zwrot, to no-op', function () {
    $order = OrderMother::expired();
    $order->recordPayment(PaymentId::fromString('pay-9'), Money::fromCents(4498), OrderMother::now()->modify('+30 minutes'));
    $order->releaseEvents();

    $order->recordPayment(PaymentId::fromString('pay-9'), Money::fromCents(4498), OrderMother::now()->modify('+31 minutes'));

    expect($order->releaseEvents())->toBe([])
        ->and($order->refunds())->toHaveCount(1);
});

test('po zwrocie za złą kwotę kupujący może jeszcze zapłacić poprawnie', function () {
    $order = OrderMother::awaitingPayment();
    $order->recordPayment(PaymentId::fromString('pay-1'), Money::fromCents(100), OrderMother::now()->modify('+1 minute'));

    $order->recordPayment(PaymentId::fromString('pay-2'), Money::fromCents(4498), OrderMother::now()->modify('+2 minutes'));

    expect($order->status())->toBe(OrderStatus::Paid)
        ->and($order->payment()->paymentId->value())->toBe('pay-2')
        ->and($order->refunds())->toHaveCount(1);
});

test('nieudana płatność nie zmienia statusu', function () {
    $order = OrderMother::awaitingPayment();
    $now = OrderMother::now()->modify('+3 minutes');

    $order->recordPaymentFailure(PaymentId::fromString('pay-1'), $now);

    expect($order->status())->toBe(OrderStatus::AwaitingPayment)
        ->and($order->payment())->toBeNull();

    $events = $order->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(OrderPaymentFailed::class)
        ->and($events[0]->paymentId->value())->toBe('pay-1')
        ->and($events[0]->occurredAt())->toEqual($now);
});

test('nieudana płatność poza AwaitingPayment to no-op', function (OrderStatus $status) {
    $order = OrderMother::inStatus($status);

    $order->recordPaymentFailure(PaymentId::fromString('pay-1'), OrderMother::now());

    expect($order->releaseEvents())->toBe([])
        ->and($order->status())->toBe($status);
})->with([OrderStatus::Placed, OrderStatus::Paid, OrderStatus::Expired]);
