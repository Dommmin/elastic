<?php

use Ordering\Domain\BuyerId;
use Ordering\Domain\CancelledBy;
use Ordering\Domain\Event\LatePaymentReceived;
use Ordering\Domain\Event\OrderAwaitingPayment;
use Ordering\Domain\Event\OrderCancelled;
use Ordering\Domain\Event\OrderExpired;
use Ordering\Domain\Event\OrderLineReserved;
use Ordering\Domain\Event\OrderPaid;
use Ordering\Domain\Event\OrderPaymentFailed;
use Ordering\Domain\Event\OrderPlaced;
use Ordering\Domain\Event\OrderRejected;
use Ordering\Domain\Event\OrderShipped;
use Ordering\Domain\Event\RefundCompleted;
use Ordering\Domain\Event\RefundRequested;
use Ordering\Domain\OfferId;
use Ordering\Domain\OrderId;
use Ordering\Domain\PaymentId;
use Ordering\Domain\Reason;
use Ordering\Domain\RefundId;
use Ordering\Domain\RefundReason;
use Ordering\Domain\RejectionReason;
use Ordering\Domain\SellerId;
use Ordering\Domain\TrackingNumber;
use Ordering\Infrastructure\Persistence\OrderEventLog;
use SharedKernel\DomainEvent;
use SharedKernel\Money;

test('każde zdarzenie domeny ma jawnie przypisany typ i payload', function () {
    $at = new DateTimeImmutable('2026-10-16 12:00:00');
    $o = OrderId::fromInt(1);
    $p = PaymentId::fromString('pay-1');
    $r = RefundId::fromString('ref-1');
    $m = Money::fromCents(100);

    $cases = [
        'order.placed' => new OrderPlaced($o, BuyerId::fromInt(2), SellerId::fromInt(3), $m, $at),
        'order.line_reserved' => new OrderLineReserved($o, OfferId::fromInt(4), $at),
        'order.awaiting_payment' => new OrderAwaitingPayment($o, $at, $at),
        'order.rejected' => new OrderRejected($o, RejectionReason::OutOfStock, OfferId::fromInt(4), $at),
        'order.paid' => new OrderPaid($o, $p, $m, $at),
        'order.payment_failed' => new OrderPaymentFailed($o, $p, $at),
        'order.late_payment_received' => new LatePaymentReceived($o, $p, $m, $at),
        'order.expired' => new OrderExpired($o, $at),
        'order.cancelled' => new OrderCancelled($o, CancelledBy::Seller, Reason::fromString('Brak'), $at),
        'order.shipped' => new OrderShipped($o, TrackingNumber::fromString('PL123'), $at),
        'order.refund_requested' => new RefundRequested($o, $r, $p, $m, RefundReason::LatePayment, $at),
        'order.refund_completed' => new RefundCompleted($o, $r, $at),
    ];

    expect($cases)->toHaveCount(12);

    foreach ($cases as $type => $event) {
        expect(OrderEventLog::type($event))->toBe($type)
            ->and(OrderEventLog::payload($event)['order_id'])->toBe(1);
    }

    expect(OrderEventLog::payload($cases['order.cancelled']))->toBe(['order_id' => 1, 'by' => 'seller', 'reason' => 'Brak']);
});

test('nieznane zdarzenie przerywa zapis', function () {
    $event = new class implements DomainEvent
    {
        public function occurredAt(): DateTimeImmutable
        {
            return new DateTimeImmutable;
        }
    };

    expect(fn () => OrderEventLog::type($event))->toThrow(LogicException::class)
        ->and(fn () => OrderEventLog::payload($event))->toThrow(LogicException::class);
});
