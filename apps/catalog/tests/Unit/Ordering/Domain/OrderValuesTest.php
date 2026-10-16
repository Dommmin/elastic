<?php

use Ordering\Domain\BuyerId;
use Ordering\Domain\InvalidOrderTransition;
use Ordering\Domain\LineQuantity;
use Ordering\Domain\LineStatus;
use Ordering\Domain\OfferId;
use Ordering\Domain\OrderAction;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderLine;
use Ordering\Domain\OrderNotFound;
use Ordering\Domain\OrderStatus;
use Ordering\Domain\PaymentId;
use Ordering\Domain\ProductName;
use Ordering\Domain\Reason;
use Ordering\Domain\RefundId;
use Ordering\Domain\SellerId;
use Ordering\Domain\TrackingNumber;
use SharedKernel\InvalidValue;
use SharedKernel\Money;

test('identyfikatory liczbowe muszą być dodatnie', function (string $class) {
    expect($class::fromInt(1)->value())->toBe(1)
        ->and($class::fromInt(5)->equals($class::fromInt(5)))->toBeTrue()
        ->and($class::fromInt(5)->equals($class::fromInt(6)))->toBeFalse()
        ->and(fn () => $class::fromInt(0))->toThrow(InvalidValue::class)
        ->and(fn () => $class::fromInt(-1))->toThrow(InvalidValue::class);
})->with([OrderId::class, BuyerId::class, SellerId::class, OfferId::class]);

test('identyfikatory bramki mają od 1 do 64 znaków', function (string $class) {
    expect($class::fromString('a')->value())->toBe('a')
        ->and($class::fromString(str_repeat('ż', 64))->value())->toBe(str_repeat('ż', 64))
        ->and($class::fromString('x-1')->equals($class::fromString('x-1')))->toBeTrue()
        ->and($class::fromString('x-1')->equals($class::fromString('x-2')))->toBeFalse()
        ->and(fn () => $class::fromString(''))->toThrow(InvalidValue::class)
        ->and(fn () => $class::fromString(str_repeat('a', 65)))->toThrow(InvalidValue::class);
})->with([PaymentId::class, RefundId::class]);

test('ilość w pozycji to 1–10', function () {
    expect(LineQuantity::fromInt(1)->value())->toBe(1)
        ->and(LineQuantity::fromInt(10)->value())->toBe(10)
        ->and(LineQuantity::fromInt(3)->equals(LineQuantity::fromInt(3)))->toBeTrue()
        ->and(fn () => LineQuantity::fromInt(0))->toThrow(InvalidValue::class)
        ->and(fn () => LineQuantity::fromInt(11))->toThrow(InvalidValue::class);
});

test('nazwa produktu ma 1–255 znaków po przycięciu', function () {
    expect(ProductName::fromString('  Lampa  ')->value())->toBe('Lampa')
        ->and(ProductName::fromString(str_repeat('ą', 255))->value())->toBe(str_repeat('ą', 255))
        ->and(ProductName::fromString('Lampa')->equals(ProductName::fromString(' Lampa ')))->toBeTrue()
        ->and(fn () => ProductName::fromString('   '))->toThrow(InvalidValue::class)
        ->and(fn () => ProductName::fromString(str_repeat('a', 256)))->toThrow(InvalidValue::class);
});

test('numer przesyłki ma 3–64 znaki po przycięciu', function () {
    expect(TrackingNumber::fromString('  PL1  ')->value())->toBe('PL1')
        ->and(TrackingNumber::fromString(str_repeat('9', 64))->value())->toBe(str_repeat('9', 64))
        ->and(TrackingNumber::fromString('PL1')->equals(TrackingNumber::fromString('PL1')))->toBeTrue()
        ->and(fn () => TrackingNumber::fromString('    '))->toThrow(InvalidValue::class)
        ->and(fn () => TrackingNumber::fromString(' P1 '))->toThrow(InvalidValue::class)
        ->and(fn () => TrackingNumber::fromString("\u{00A0}\u{00A0}\u{00A0}"))->toThrow(InvalidValue::class)
        ->and(fn () => TrackingNumber::fromString(str_repeat('9', 65)))->toThrow(InvalidValue::class);
});

test('numer przesyłki bez znaków sterujących i niewidocznych', function (string $value) {
    expect(fn () => TrackingNumber::fromString($value))->toThrow(InvalidValue::class);
})->with([
    'NUL' => "PL1\x002",
    'escape ANSI' => "PL\e[31m123",
    'nowa linia w środku' => "PL1\n23",
    'tabulator w środku' => "PL1\t23",
    'DEL' => "PL1\x7F23",
    'zero-width space' => "PL1\u{200B}23",
]);

test('powód jest niepusty po przycięciu i ma najwyżej 500 znaków', function () {
    expect(Reason::fromString("  Brak towaru\n ")->value())->toBe('Brak towaru')
        ->and(Reason::fromString(str_repeat('ź', 500))->value())->toBe(str_repeat('ź', 500))
        ->and(Reason::fromString('A')->equals(Reason::fromString(' A ')))->toBeTrue()
        ->and(fn () => Reason::fromString(" \t "))->toThrow(InvalidValue::class)
        ->and(fn () => Reason::fromString("\u{00A0}"))->toThrow(InvalidValue::class)
        ->and(fn () => Reason::fromString(str_repeat('a', 501)))->toThrow(InvalidValue::class);
});

test('pozycja liczy wartość jako cenę razy ilość i zaczyna jako Pending', function () {
    $line = OrderLine::create(OfferId::fromInt(1), ProductName::fromString('Lampa'), Money::fromCents(1999), LineQuantity::fromInt(3));

    expect($line->lineTotal()->cents())->toBe(5997)
        ->and($line->status())->toBe(LineStatus::Pending)
        ->and($line->reserved()->status())->toBe(LineStatus::Reserved)
        ->and($line->status())->toBe(LineStatus::Pending);

    $restored = OrderLine::reconstitute(OfferId::fromInt(1), ProductName::fromString('Lampa'), Money::fromCents(1999), LineQuantity::fromInt(3), LineStatus::Reserved);
    expect($restored->status())->toBe(LineStatus::Reserved)
        ->and($restored->offerId()->value())->toBe(1)
        ->and($restored->productName()->value())->toBe('Lampa')
        ->and($restored->unitPrice()->cents())->toBe(1999)
        ->and($restored->quantity()->value())->toBe(3);
});

test('błędy zamówienia mają stabilne kody reguł', function () {
    expect(InvalidOrderTransition::CODE)->toBe('invalid_order_transition')
        ->and(OrderNotFound::CODE)->toBe('order_not_found')
        ->and(InvalidOrderTransition::from(OrderStatus::Paid, OrderAction::Cancel)->getMessage())->toContain('cancel')
        ->and(InvalidOrderTransition::from(OrderStatus::Paid, 'mark_line_reserved')->getMessage())->toContain('mark_line_reserved');
});

test('wartości enumów są kontraktem z bazą i UI', function () {
    expect(array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::cases()))
        ->toBe(['placed', 'awaiting_payment', 'paid', 'shipped', 'rejected', 'expired', 'cancelled'])
        ->and(array_map(static fn (OrderAction $a): string => $a->value, OrderAction::cases()))
        ->toBe(['pay', 'cancel', 'ship', 'cancel_by_seller']);
});
