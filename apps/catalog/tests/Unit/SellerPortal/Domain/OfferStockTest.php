<?php

use SellerPortal\Domain\Offer\Condition;
use SellerPortal\Domain\Offer\Event\OfferBackInStock;
use SellerPortal\Domain\Offer\Event\OfferSoldOut;
use SellerPortal\Domain\Offer\Event\OfferStockChanged;
use SellerPortal\Domain\Offer\Offer;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferStatus;
use SellerPortal\Domain\Offer\ProductId;
use SellerPortal\Domain\Offer\ShippingTime;
use SellerPortal\Domain\Offer\Stock;
use SellerPortal\Domain\Offer\SuspensionReason;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Shared\InvalidValue;
use SellerPortal\Domain\Shared\Money;

function offerStockOffer(OfferStatus $status, int $stock): Offer
{
    return Offer::reconstitute(
        OfferId::fromInt(10), SellerId::fromInt(1), ProductId::fromInt(99), Money::fromCents(10000),
        Stock::fromInt($stock), Condition::New, ShippingTime::fromInt(3), $status,
        $status === OfferStatus::Suspended ? SuspensionReason::sellerBlocked() : null, null, [], 2,
    );
}

function offerStockNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-02-01 10:00:00');
}

test('Stock i ShippingTime pilnują zakresów', function () {
    expect(Stock::fromInt(0)->value())->toBe(0)
        ->and(Stock::fromInt(100000)->value())->toBe(100000)
        ->and(fn () => Stock::fromInt(-1))->toThrow(InvalidValue::class)
        ->and(fn () => Stock::fromInt(100001))->toThrow(InvalidValue::class)
        ->and(ShippingTime::fromInt(1)->value())->toBe(1)
        ->and(ShippingTime::fromInt(30)->value())->toBe(30)
        ->and(fn () => ShippingTime::fromInt(0))->toThrow(InvalidValue::class)
        ->and(fn () => ShippingTime::fromInt(31))->toThrow(InvalidValue::class)
        ->and(fn () => OfferId::fromInt(0))->toThrow(InvalidValue::class)
        ->and(fn () => ProductId::fromInt(-3))->toThrow(InvalidValue::class);
});

test('stan >0→0 na opublikowanej daje OfferSoldOut i isSoldOut()', function () {
    $offer = offerStockOffer(OfferStatus::Published, 3);
    expect($offer->isListed())->toBeTrue()->and($offer->isSoldOut())->toBeFalse();

    $offer->changeStock(Stock::fromInt(0), offerStockNow());

    expect($offer->isSoldOut())->toBeTrue()
        ->and($offer->isListed())->toBeFalse()
        ->and($offer->pendingEvents())->toHaveCount(1)
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferSoldOut::class);
});

test('stan 0→5 na opublikowanej daje OfferBackInStock', function () {
    $offer = offerStockOffer(OfferStatus::Published, 0);

    $offer->changeStock(Stock::fromInt(5), offerStockNow());

    expect($offer->isListed())->toBeTrue()
        ->and($offer->pendingEvents())->toHaveCount(1)
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferBackInStock::class)
        ->and($offer->pendingEvents()[0]->new->value())->toBe(5);
});

test('stan 3→7 daje OfferStockChanged(old 3, new 7)', function () {
    $offer = offerStockOffer(OfferStatus::Published, 3);

    $offer->changeStock(Stock::fromInt(7), offerStockNow());

    $event = $offer->pendingEvents()[0];
    expect($offer->pendingEvents())->toHaveCount(1)
        ->and($event)->toBeInstanceOf(OfferStockChanged::class)
        ->and($event->old->value())->toBe(3)
        ->and($event->new->value())->toBe(7);
});

test('zmiana stanu w Draft daje OfferStockChanged, nigdy SoldOut', function () {
    $offer = offerStockOffer(OfferStatus::Draft, 3);

    $offer->changeStock(Stock::fromInt(0), offerStockNow());
    $offer->changeStock(Stock::fromInt(4), offerStockNow());

    expect($offer->pendingEvents())->toHaveCount(2)
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferStockChanged::class)
        ->and($offer->pendingEvents()[1])->toBeInstanceOf(OfferStockChanged::class)
        ->and($offer->isSoldOut())->toBeFalse();
});

test('zmiana stanu w Suspended to zwykła zmiana stanu', function () {
    $offer = offerStockOffer(OfferStatus::Suspended, 3);

    $offer->changeStock(Stock::fromInt(0), offerStockNow());

    expect($offer->stock()->value())->toBe(0)
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferStockChanged::class);
});

test('ten sam stan nie generuje zdarzenia', function () {
    $offer = offerStockOffer(OfferStatus::Published, 3);

    $offer->changeStock(Stock::fromInt(3), offerStockNow());

    expect($offer->pendingEvents())->toBe([]);
});
