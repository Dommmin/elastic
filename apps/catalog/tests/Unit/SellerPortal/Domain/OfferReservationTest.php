<?php

use SellerPortal\Domain\Offer\Condition;
use SellerPortal\Domain\Offer\Event\OfferReservationConfirmed;
use SellerPortal\Domain\Offer\Event\OfferReservationReleased;
use SellerPortal\Domain\Offer\Event\OfferStockReserved;
use SellerPortal\Domain\Offer\Event\OfferWithdrawn;
use SellerPortal\Domain\Offer\InsufficientStock;
use SellerPortal\Domain\Offer\Offer;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferNotReservable;
use SellerPortal\Domain\Offer\OfferStatus;
use SellerPortal\Domain\Offer\PendingPriceChange;
use SellerPortal\Domain\Offer\ProductId;
use SellerPortal\Domain\Offer\Quantity;
use SellerPortal\Domain\Offer\Reservation;
use SellerPortal\Domain\Offer\ReservationConflict;
use SellerPortal\Domain\Offer\ReservationKey;
use SellerPortal\Domain\Offer\ReservationNotFound;
use SellerPortal\Domain\Offer\ReservationPriceMismatch;
use SellerPortal\Domain\Offer\ReservationStatus;
use SellerPortal\Domain\Offer\ShippingTime;
use SellerPortal\Domain\Offer\Stock;
use SellerPortal\Domain\Offer\StockBelowReserved;
use SellerPortal\Domain\Offer\SuspensionReason;
use SellerPortal\Domain\Seller\City;
use SellerPortal\Domain\Seller\Seller;
use SellerPortal\Domain\Seller\SellerCannotSell;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Seller\SellerName;
use SellerPortal\Domain\Seller\SellerStatus;
use SellerPortal\Domain\Shared\UserId;
use SharedKernel\InvalidValue;
use SharedKernel\Money;

function offerReservationNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-02-01 10:00:00');
}

function offerReservationSeller(SellerStatus $status = SellerStatus::Active): Seller
{
    return Seller::reconstitute(SellerId::fromInt(1), UserId::fromInt(7), SellerName::fromString('Sklep Testowy'), City::fromString('Kraków'), $status, null, 1);
}

/**
 * @param  array<string, int>  $reservations  klucz => ilość
 */
function offerReservationOffer(
    OfferStatus $status = OfferStatus::Published,
    int $stock = 5,
    array $reservations = [],
    ?PendingPriceChange $pending = null,
): Offer {
    return Offer::reconstitute(
        OfferId::fromInt(10), SellerId::fromInt(1), ProductId::fromInt(99), Money::fromCents(10000),
        Stock::fromInt($stock), Condition::New, ShippingTime::fromInt(3), $status,
        $status === OfferStatus::Suspended ? SuspensionReason::sellerBlocked() : null, $pending, [],
        array_map(
            static fn (string $key, int $quantity): Reservation => new Reservation(ReservationKey::fromString($key), Quantity::fromInt($quantity), offerReservationNow()->modify('-1 minute')),
            array_keys($reservations),
            array_values($reservations),
        ),
        2,
    );
}

function offerReservationReserve(Offer $offer, string $key, int $quantity, int $cents = 10000): void
{
    $offer->reserve(offerReservationSeller(), ReservationKey::fromString($key), Quantity::fromInt($quantity), Money::fromCents($cents), offerReservationNow());
}

test('ReservationKey, Quantity i ReservationStatus pilnują zakresów', function () {
    expect(ReservationKey::fromString('a')->value())->toBe('a')
        ->and(ReservationKey::fromString(str_repeat('x', 64))->value())->toBe(str_repeat('x', 64))
        ->and(ReservationKey::fromString('order-1')->equals(ReservationKey::fromString('order-1')))->toBeTrue()
        ->and(ReservationKey::fromString('order-1')->equals(ReservationKey::fromString('order-2')))->toBeFalse()
        ->and(fn () => ReservationKey::fromString(''))->toThrow(InvalidValue::class)
        ->and(fn () => ReservationKey::fromString(str_repeat('x', 65)))->toThrow(InvalidValue::class)
        ->and(Quantity::fromInt(1)->value())->toBe(1)
        ->and(Quantity::fromInt(100000)->value())->toBe(100000)
        ->and(fn () => Quantity::fromInt(0))->toThrow(InvalidValue::class)
        ->and(fn () => Quantity::fromInt(100001))->toThrow(InvalidValue::class)
        ->and(array_map(static fn (ReservationStatus $s): string => $s->value, ReservationStatus::cases()))->toBe(['active', 'confirmed', 'released']);
});

test('rezerwacja zmniejsza dostępne, nie stan', function () {
    $offer = offerReservationOffer(stock: 5);

    offerReservationReserve($offer, 'order-1', 2);

    $event = $offer->pendingEvents()[0];
    expect($offer->stock()->value())->toBe(5)
        ->and($offer->reserved())->toBe(2)
        ->and($offer->available())->toBe(3)
        ->and($offer->reservations())->toHaveCount(1)
        ->and($offer->reservations()[0]->key->value())->toBe('order-1')
        ->and($offer->reservations()[0]->reservedAt)->toEqual(offerReservationNow())
        ->and($offer->pendingEvents())->toHaveCount(1)
        ->and($event)->toBeInstanceOf(OfferStockReserved::class)
        ->and($event->offerId->value())->toBe(10)
        ->and($event->key->value())->toBe('order-1')
        ->and($event->quantity->value())->toBe(2)
        ->and($event->available)->toBe(3)
        ->and($event->listed)->toBeTrue()
        ->and($event->occurredAt)->toEqual(offerReservationNow());
});

test('rezerwacja poza Published rzuca OfferNotReservable', function (OfferStatus $status) {
    $offer = offerReservationOffer($status);

    expect(fn () => offerReservationReserve($offer, 'order-1', 1))->toThrow(OfferNotReservable::class)
        ->and($offer->pendingEvents())->toBe([]);
})->with([
    'draft' => OfferStatus::Draft,
    'suspended' => OfferStatus::Suspended,
    'withdrawn' => OfferStatus::Withdrawn,
]);

test('zablokowany sprzedawca nie rezerwuje', function () {
    $offer = offerReservationOffer();

    expect(fn () => $offer->reserve(offerReservationSeller(SellerStatus::Blocked), ReservationKey::fromString('order-1'), Quantity::fromInt(1), Money::fromCents(10000), offerReservationNow()))
        ->toThrow(SellerCannotSell::class)
        ->and($offer->reserved())->toBe(0);
});

test('cena inna niż obecna rzuca ReservationPriceMismatch', function () {
    $offer = offerReservationOffer();

    expect(fn () => offerReservationReserve($offer, 'order-1', 1, 9999))->toThrow(ReservationPriceMismatch::class)
        ->and($offer->reserved())->toBe(0);
});

test('oczekująca zmiana ceny nie przeszkadza: liczy się obecna cena', function () {
    $offer = offerReservationOffer(pending: new PendingPriceChange(Money::fromCents(20000), offerReservationNow()));

    expect(fn () => offerReservationReserve($offer, 'order-1', 1, 20000))->toThrow(ReservationPriceMismatch::class);

    offerReservationReserve($offer, 'order-1', 1, 10000);

    expect($offer->reserved())->toBe(1);
});

test('więcej niż dostępne rzuca InsufficientStock', function () {
    $offer = offerReservationOffer(stock: 3);

    offerReservationReserve($offer, 'a', 2);

    expect(fn () => offerReservationReserve($offer, 'b', 2))->toThrow(InsufficientStock::class)
        ->and($offer->reserved())->toBe(2)
        ->and($offer->pendingEvents())->toHaveCount(1);
});

test('ten sam klucz i ilość to no-op bez zdarzenia', function () {
    $offer = offerReservationOffer(stock: 5, reservations: ['order-1' => 2]);

    offerReservationReserve($offer, 'order-1', 2);

    expect($offer->reserved())->toBe(2)
        ->and($offer->reservations())->toHaveCount(1)
        ->and($offer->pendingEvents())->toBe([]);
});

test('ten sam klucz, inna ilość rzuca ReservationConflict', function () {
    $offer = offerReservationOffer(stock: 5, reservations: ['order-1' => 2]);

    expect(fn () => offerReservationReserve($offer, 'order-1', 3))->toThrow(ReservationConflict::class)
        ->and($offer->reserved())->toBe(2);
});

test('ostatnia sztuka zarezerwowana: oferta wyprzedana i niewidoczna', function () {
    $offer = offerReservationOffer(stock: 1);

    offerReservationReserve($offer, 'order-1', 1);

    expect($offer->isListed())->toBeFalse()
        ->and($offer->isSoldOut())->toBeTrue()
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferStockReserved::class)
        ->and($offer->pendingEvents()[0]->available)->toBe(0)
        ->and($offer->pendingEvents()[0]->listed)->toBeFalse();
});

test('zwolnienie działa w każdym statusie, także po wycofaniu', function (OfferStatus $status) {
    $offer = offerReservationOffer($status, stock: 5, reservations: ['order-1' => 2, 'order-2' => 1]);

    $offer->releaseReservation(ReservationKey::fromString('order-1'), offerReservationNow());

    $event = $offer->pendingEvents()[0];
    expect($offer->reserved())->toBe(1)
        ->and($offer->available())->toBe(4)
        ->and($offer->stock()->value())->toBe(5)
        ->and($offer->reservations())->toHaveCount(1)
        ->and($offer->reservations()[0]->key->value())->toBe('order-2')
        ->and($offer->pendingEvents())->toHaveCount(1)
        ->and($event)->toBeInstanceOf(OfferReservationReleased::class)
        ->and($event->key->value())->toBe('order-1')
        ->and($event->quantity->value())->toBe(2)
        ->and($event->available)->toBe(4)
        ->and($event->listed)->toBe($status === OfferStatus::Published);
})->with([
    'draft' => OfferStatus::Draft,
    'published' => OfferStatus::Published,
    'suspended' => OfferStatus::Suspended,
    'withdrawn' => OfferStatus::Withdrawn,
]);

test('zwolnienie nieznanego klucza to no-op bez zdarzenia', function () {
    $offer = offerReservationOffer(reservations: ['order-1' => 2]);

    $offer->releaseReservation(ReservationKey::fromString('order-9'), offerReservationNow());

    expect($offer->reserved())->toBe(2)
        ->and($offer->pendingEvents())->toBe([]);
});

test('potwierdzenie zmniejsza stan, dostępne bez zmian', function () {
    $offer = offerReservationOffer(stock: 5);
    offerReservationReserve($offer, 'order-1', 2);

    $offer->confirmReservation(ReservationKey::fromString('order-1'), offerReservationNow());

    $event = $offer->pendingEvents()[1];
    expect($offer->stock()->value())->toBe(3)
        ->and($offer->reserved())->toBe(0)
        ->and($offer->available())->toBe(3)
        ->and($offer->reservations())->toBe([])
        ->and($offer->pendingEvents())->toHaveCount(2)
        ->and($event)->toBeInstanceOf(OfferReservationConfirmed::class)
        ->and($event->key->value())->toBe('order-1')
        ->and($event->quantity->value())->toBe(2)
        ->and($event->stock->value())->toBe(3);
});

test('potwierdzenie działa po wycofaniu oferty', function () {
    $offer = offerReservationOffer(OfferStatus::Withdrawn, stock: 5, reservations: ['order-1' => 2]);

    $offer->confirmReservation(ReservationKey::fromString('order-1'), offerReservationNow());

    expect($offer->stock()->value())->toBe(3)
        ->and($offer->reserved())->toBe(0);
});

test('potwierdzenie nieznanego klucza rzuca ReservationNotFound', function () {
    $offer = offerReservationOffer(reservations: ['order-1' => 2]);

    expect(fn () => $offer->confirmReservation(ReservationKey::fromString('order-9'), offerReservationNow()))->toThrow(ReservationNotFound::class)
        ->and($offer->stock()->value())->toBe(5);
});

test('wycofanie nie zwalnia rezerwacji', function () {
    $offer = offerReservationOffer(stock: 5, reservations: ['order-1' => 2]);

    $offer->withdraw(offerReservationNow());

    expect($offer->reserved())->toBe(2)
        ->and($offer->reservations())->toHaveCount(1)
        ->and($offer->pendingEvents())->toHaveCount(1)
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferWithdrawn::class);
});

test('stan poniżej zarezerwowanego rzuca StockBelowReserved, równy przechodzi', function () {
    $offer = offerReservationOffer(stock: 5, reservations: ['order-1' => 3]);

    expect(fn () => $offer->changeStock(Stock::fromInt(2), offerReservationNow()))->toThrow(StockBelowReserved::class)
        ->and($offer->stock()->value())->toBe(5);

    $offer->changeStock(Stock::fromInt(3), offerReservationNow());

    expect($offer->stock()->value())->toBe(3)
        ->and($offer->available())->toBe(0);
});
