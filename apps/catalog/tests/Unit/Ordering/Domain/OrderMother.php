<?php

namespace Tests\Unit\Ordering\Domain;

use DateTimeImmutable;
use Ordering\Domain\BuyerId;
use Ordering\Domain\LineQuantity;
use Ordering\Domain\OfferId;
use Ordering\Domain\Order;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderLine;
use Ordering\Domain\OrderStatus;
use Ordering\Domain\PaymentId;
use Ordering\Domain\ProductName;
use Ordering\Domain\Reason;
use Ordering\Domain\RejectionReason;
use Ordering\Domain\SellerId;
use Ordering\Domain\TrackingNumber;
use SharedKernel\Currency;
use SharedKernel\Money;

/**
 * Zamówienia w zadanym statusie, doprowadzone tam publicznymi metodami
 * agregatu (nie przez `reconstitute`), żeby testy sprawdzały prawdziwe
 * przejścia. Domyślne zamówienie: 2 × 1999 + 1 × 500 = 4498 groszy.
 */
final class OrderMother
{
    public const int TOTAL_CENTS = 4498;

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-16 12:00:00');
    }

    public static function line(int $offerId, int $unitCents, int $quantity, Currency $currency = Currency::PLN): OrderLine
    {
        return OrderLine::create(
            OfferId::fromInt($offerId),
            ProductName::fromString("Produkt {$offerId}"),
            Money::fromCents($unitCents, $currency),
            LineQuantity::fromInt($quantity),
        );
    }

    /**
     * @return list<OrderLine>
     */
    public static function defaultLines(): array
    {
        return [self::line(1, 1999, 2), self::line(2, 500, 1)];
    }

    public static function total(): Money
    {
        return Money::fromCents(self::TOTAL_CENTS);
    }

    public static function placed(?DateTimeImmutable $now = null): Order
    {
        $order = Order::place(OrderId::fromInt(7), BuyerId::fromInt(3), SellerId::fromInt(5), self::defaultLines(), $now ?? self::now());
        $order->releaseEvents();

        return $order;
    }

    public static function awaitingPayment(?DateTimeImmutable $now = null): Order
    {
        $now ??= self::now();
        $order = self::placed($now);
        $order->markLineReserved(OfferId::fromInt(1), $now);
        $order->markLineReserved(OfferId::fromInt(2), $now);
        $order->releaseEvents();

        return $order;
    }

    public static function paid(string $paymentId = 'pay-1'): Order
    {
        $order = self::awaitingPayment();
        $order->recordPayment(PaymentId::fromString($paymentId), self::total(), self::now()->modify('+5 minutes'));
        $order->releaseEvents();

        return $order;
    }

    public static function shipped(): Order
    {
        $order = self::paid();
        $order->ship(TrackingNumber::fromString('PL123456'), self::now()->modify('+1 day'));
        $order->releaseEvents();

        return $order;
    }

    public static function cancelledBySeller(): Order
    {
        $order = self::paid();
        $order->cancelBySeller(Reason::fromString('Brak towaru'), self::now()->modify('+1 hour'));
        $order->releaseEvents();

        return $order;
    }

    public static function cancelledByBuyer(): Order
    {
        $order = self::awaitingPayment();
        $order->cancelByBuyer(self::now()->modify('+1 minute'));
        $order->releaseEvents();

        return $order;
    }

    public static function expired(): Order
    {
        $order = self::awaitingPayment();
        $order->expire(self::now()->modify('+20 minutes'));
        $order->releaseEvents();

        return $order;
    }

    public static function rejected(): Order
    {
        $order = self::placed();
        $order->reject(RejectionReason::OutOfStock, OfferId::fromInt(2), self::now());
        $order->releaseEvents();

        return $order;
    }

    public static function inStatus(OrderStatus $status): Order
    {
        return match ($status) {
            OrderStatus::Placed => self::placed(),
            OrderStatus::AwaitingPayment => self::awaitingPayment(),
            OrderStatus::Paid => self::paid(),
            OrderStatus::Shipped => self::shipped(),
            OrderStatus::Rejected => self::rejected(),
            OrderStatus::Expired => self::expired(),
            OrderStatus::Cancelled => self::cancelledByBuyer(),
        };
    }

    /**
     * @return list<class-string>
     */
    public static function eventClasses(Order $order): array
    {
        return array_map(static fn (object $event): string => $event::class, $order->releaseEvents());
    }
}
