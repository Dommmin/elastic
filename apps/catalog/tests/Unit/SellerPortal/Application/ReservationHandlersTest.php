<?php

use SellerPortal\Application\Reservation\ConfirmReservation;
use SellerPortal\Application\Reservation\ConfirmReservationHandler;
use SellerPortal\Application\Reservation\ReleaseReservation;
use SellerPortal\Application\Reservation\ReleaseReservationHandler;
use SellerPortal\Application\Reservation\ReserveStock;
use SellerPortal\Application\Reservation\ReserveStockHandler;
use SellerPortal\Domain\Offer\Event\OfferReservationConfirmed;
use SellerPortal\Domain\Offer\Event\OfferStockReserved;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\ReservationConflict;
use SellerPortal\Domain\Offer\ReservationNotFound;
use SellerPortal\Domain\Seller\SellerCannotSell;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Reason;
use SharedKernel\ConcurrencyConflict;
use Tests\Unit\SellerPortal\InMemory\PortalWorld;

function reservationHandlersPublishedOffer(PortalWorld $w, int $stock = 5): OfferId
{
    $w->activeSeller(10);
    $id = $w->draft(10, stock: $stock, priceCents: 10000);
    $offer = $w->offers->get($id);
    $offer->publish($w->sellers->get($offer->sellerId()), $w->clock->now());
    $w->offers->save($offer);
    $w->forgetDispatched();

    return $id;
}

function reservationHandlersReserve(PortalWorld $w, OfferId $id, string $key = 'order-1', int $quantity = 2): void
{
    (new ReserveStockHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers, $w->sellers))
        ->handle(new ReserveStock($key, $id->value(), $quantity, 10000, 'PLN'));
}

function reservationHandlersConfirm(PortalWorld $w, OfferId $id, string $key = 'order-1'): void
{
    (new ConfirmReservationHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))
        ->handle(new ConfirmReservation($key, $id->value()));
}

function reservationHandlersRelease(PortalWorld $w, OfferId $id, string $key = 'order-1'): void
{
    (new ReleaseReservationHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))
        ->handle(new ReleaseReservation($key, $id->value()));
}

test('ReserveStock rezerwuje i rozsyła OfferStockReserved', function () {
    $w = new PortalWorld;
    $id = reservationHandlersPublishedOffer($w);

    reservationHandlersReserve($w, $id);

    expect($w->offers->get($id))->reserved()->toBe(2)->available()->toBe(3)
        ->and($w->dispatcher->classes())->toBe([OfferStockReserved::class]);
});

test('ReserveStock bierze sprzedawcę z oferty: zablokowany sprzedawca nie przyjmie rezerwacji', function () {
    $w = new PortalWorld;
    $id = reservationHandlersPublishedOffer($w);
    $seller = $w->sellers->get($w->offers->get($id)->sellerId());
    $seller->block(ModeratorId::fromInt(900), Reason::fromString('test'), $w->clock->now());
    $w->sellers->save($seller);

    expect(fn () => reservationHandlersReserve($w, $id))->toThrow(SellerCannotSell::class);
});

test('ReserveStock dla zwolnionego klucza rzuca ReservationConflict (R12)', function () {
    $w = new PortalWorld;
    $id = reservationHandlersPublishedOffer($w);
    reservationHandlersReserve($w, $id);
    reservationHandlersRelease($w, $id);

    expect(fn () => reservationHandlersReserve($w, $id))->toThrow(ReservationConflict::class);
});

test('ReserveStock dla potwierdzonego klucza rzuca ReservationConflict (R12)', function () {
    $w = new PortalWorld;
    $id = reservationHandlersPublishedOffer($w);
    reservationHandlersReserve($w, $id);
    reservationHandlersConfirm($w, $id);

    expect(fn () => reservationHandlersReserve($w, $id))->toThrow(ReservationConflict::class);
});

test('ReleaseReservation zwalnia rezerwację, a drugi raz to no-op', function () {
    $w = new PortalWorld;
    $id = reservationHandlersPublishedOffer($w);
    reservationHandlersReserve($w, $id);

    reservationHandlersRelease($w, $id);
    reservationHandlersRelease($w, $id);

    expect($w->offers->get($id))->reserved()->toBe(0);
});

test('ConfirmReservation zmniejsza stan', function () {
    $w = new PortalWorld;
    $id = reservationHandlersPublishedOffer($w);
    reservationHandlersReserve($w, $id);
    $w->forgetDispatched();

    reservationHandlersConfirm($w, $id);

    expect($w->offers->get($id)->stock()->value())->toBe(3)
        ->and($w->dispatcher->classes())->toBe([OfferReservationConfirmed::class]);
});

test('ConfirmReservation drugi raz to no-op', function () {
    $w = new PortalWorld;
    $id = reservationHandlersPublishedOffer($w);
    reservationHandlersReserve($w, $id);
    reservationHandlersConfirm($w, $id);
    $w->forgetDispatched();

    reservationHandlersConfirm($w, $id);

    expect($w->offers->get($id)->stock()->value())->toBe(3)
        ->and($w->dispatcher->dispatched)->toBe([]);
});

test('ConfirmReservation po zwolnieniu rzuca ReservationNotFound', function () {
    $w = new PortalWorld;
    $id = reservationHandlersPublishedOffer($w);
    reservationHandlersReserve($w, $id);
    reservationHandlersRelease($w, $id);

    expect(fn () => reservationHandlersConfirm($w, $id))->toThrow(ReservationNotFound::class)
        ->and($w->offers->get($id)->stock()->value())->toBe(5);
});

test('konflikt wersji jest ponawiany do 3 razy', function () {
    $w = new PortalWorld;
    $id = reservationHandlersPublishedOffer($w);

    $w->offers->failNextSaves(2);
    reservationHandlersReserve($w, $id);
    expect($w->offers->get($id)->reserved())->toBe(2);

    $w->offers->failNextSaves(3);
    expect(fn () => reservationHandlersReserve($w, $id, 'order-2', 1))->toThrow(ConcurrencyConflict::class)
        ->and($w->offers->get($id)->reserved())->toBe(2);
});
