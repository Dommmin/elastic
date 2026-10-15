<?php

use SellerPortal\Domain\Offer\Actor;
use SellerPortal\Domain\Offer\Condition;
use SellerPortal\Domain\Offer\Event\OfferDrafted;
use SellerPortal\Domain\Offer\Event\OfferPublished;
use SellerPortal\Domain\Offer\Event\OfferReinstated;
use SellerPortal\Domain\Offer\Event\OfferSuspended;
use SellerPortal\Domain\Offer\Event\OfferWithdrawn;
use SellerPortal\Domain\Offer\InvalidOfferTransition;
use SellerPortal\Domain\Offer\Offer;
use SellerPortal\Domain\Offer\OfferAction;
use SellerPortal\Domain\Offer\OfferCannotBePublishedWithoutStock;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferStatus;
use SellerPortal\Domain\Offer\PendingPriceChange;
use SellerPortal\Domain\Offer\ProductId;
use SellerPortal\Domain\Offer\ShippingTime;
use SellerPortal\Domain\Offer\Stock;
use SellerPortal\Domain\Offer\SuspensionKind;
use SellerPortal\Domain\Offer\SuspensionReason;
use SellerPortal\Domain\Seller\City;
use SellerPortal\Domain\Seller\Seller;
use SellerPortal\Domain\Seller\SellerCannotSell;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Seller\SellerName;
use SellerPortal\Domain\Seller\SellerStatus;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Money;
use SellerPortal\Domain\Shared\Reason;
use SellerPortal\Domain\Shared\UserId;

function offerLifecycleNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-02-01 10:00:00');
}

function offerLifecycleSeller(SellerStatus $status = SellerStatus::Active): Seller
{
    return Seller::reconstitute(SellerId::fromInt(1), UserId::fromInt(7), SellerName::fromString('Sklep Testowy'), City::fromString('Kraków'), $status, null, 1);
}

function offerLifecycleOffer(
    OfferStatus $status = OfferStatus::Draft,
    int $stock = 5,
    ?SuspensionReason $suspension = null,
    ?PendingPriceChange $pending = null,
): Offer {
    return Offer::reconstitute(
        OfferId::fromInt(10),
        SellerId::fromInt(1),
        ProductId::fromInt(99),
        Money::fromCents(10000),
        Stock::fromInt($stock),
        Condition::New,
        ShippingTime::fromInt(3),
        $status,
        $suspension,
        $pending,
        [],
        4,
    );
}

function offerLifecyclePending(): PendingPriceChange
{
    return new PendingPriceChange(Money::fromCents(20000), offerLifecycleNow());
}

function offerLifecycleModeration(): SuspensionReason
{
    return SuspensionReason::byModerator(ModeratorId::fromInt(2), Reason::fromString('Naruszenie regulaminu'));
}

test('draftOffer tworzy Draft i OfferDrafted', function () {
    $offer = offerLifecycleSeller()->draftOffer(
        OfferId::fromInt(10), ProductId::fromInt(99), Money::fromCents(10000), Stock::fromInt(5),
        Condition::Used, ShippingTime::fromInt(2), offerLifecycleNow(),
    );

    expect($offer->status())->toBe(OfferStatus::Draft)
        ->and($offer->version())->toBe(0)
        ->and($offer->sellerId()->value())->toBe(1)
        ->and($offer->pendingPriceChange())->toBeNull()
        ->and($offer->suspension())->toBeNull()
        ->and($offer->recentPriceChanges())->toBe([])
        ->and($offer->pendingEvents())->toHaveCount(1)
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferDrafted::class)
        ->and($offer->pendingEvents()[0]->productId->value())->toBe(99);
});

test('Seller nieaktywny nie wystawi oferty', function (SellerStatus $status) {
    expect(fn () => offerLifecycleSeller($status)->draftOffer(
        OfferId::fromInt(10), ProductId::fromInt(99), Money::fromCents(10000), Stock::fromInt(5),
        Condition::New, ShippingTime::fromInt(2), offerLifecycleNow(),
    ))->toThrow(SellerCannotSell::class);
})->with([SellerStatus::Pending, SellerStatus::Rejected, SellerStatus::Blocked]);

test('publish: Draft → Published, OfferPublished', function () {
    $offer = offerLifecycleOffer();

    $offer->publish(offerLifecycleSeller(), offerLifecycleNow());

    expect($offer->status())->toBe(OfferStatus::Published)
        ->and($offer->isListed())->toBeTrue()
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferPublished::class);
});

test('publish bez stanu rzuca OfferCannotBePublishedWithoutStock', function () {
    $offer = offerLifecycleOffer(stock: 0);

    expect(fn () => $offer->publish(offerLifecycleSeller(), offerLifecycleNow()))->toThrow(OfferCannotBePublishedWithoutStock::class)
        ->and($offer->status())->toBe(OfferStatus::Draft)
        ->and($offer->pendingEvents())->toBe([]);
});

test('publish, gdy sprzedawca zablokowany, rzuca SellerCannotSell', function () {
    $offer = offerLifecycleOffer();

    expect(fn () => $offer->publish(offerLifecycleSeller(SellerStatus::Blocked), offerLifecycleNow()))->toThrow(SellerCannotSell::class)
        ->and($offer->status())->toBe(OfferStatus::Draft);
});

test('publish tylko z Draft', function (OfferStatus $status) {
    expect(fn () => offerLifecycleOffer($status)->publish(offerLifecycleSeller(), offerLifecycleNow()))->toThrow(InvalidOfferTransition::class);
})->with([OfferStatus::Published, OfferStatus::Suspended, OfferStatus::Withdrawn]);

test('suspend tylko z Published; reinstate tylko z Suspended', function () {
    $offer = offerLifecycleOffer(OfferStatus::Published);
    $offer->suspend(offerLifecycleModeration(), offerLifecycleNow());

    expect($offer->status())->toBe(OfferStatus::Suspended)
        ->and($offer->isListed())->toBeFalse()
        ->and($offer->suspension()->kind())->toBe(SuspensionKind::Moderation)
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferSuspended::class)
        ->and(fn () => $offer->suspend(offerLifecycleModeration(), offerLifecycleNow()))->toThrow(InvalidOfferTransition::class);

    $offer->reinstate(offerLifecycleNow());

    expect($offer->status())->toBe(OfferStatus::Published)
        ->and($offer->suspension())->toBeNull()
        ->and($offer->pendingEvents()[1])->toBeInstanceOf(OfferReinstated::class)
        ->and(fn () => $offer->reinstate(offerLifecycleNow()))->toThrow(InvalidOfferTransition::class);

    foreach ([OfferStatus::Draft, OfferStatus::Withdrawn] as $status) {
        expect(fn () => offerLifecycleOffer($status)->suspend(offerLifecycleModeration(), offerLifecycleNow()))->toThrow(InvalidOfferTransition::class)
            ->and(fn () => offerLifecycleOffer($status)->reinstate(offerLifecycleNow()))->toThrow(InvalidOfferTransition::class);
    }
});

test('suspend zapamiętuje rodzaj powodu', function () {
    $offer = offerLifecycleOffer(OfferStatus::Published);
    $offer->suspend(SuspensionReason::sellerBlocked(), offerLifecycleNow());

    expect($offer->suspension()->kind())->toBe(SuspensionKind::SellerBlocked)
        ->and($offer->suspension()->note())->toBeNull()
        ->and($offer->suspension()->moderatorId())->toBeNull();

    $byModerator = offerLifecycleModeration();
    expect($byModerator->kind())->toBe(SuspensionKind::Moderation)
        ->and($byModerator->moderatorId()->value())->toBe(2)
        ->and($byModerator->note()->value())->toBe('Naruszenie regulaminu');
});

test('withdraw z każdego stanu poza Withdrawn; Withdrawn jest końcowy', function (OfferStatus $status) {
    $offer = offerLifecycleOffer($status);

    $offer->withdraw(offerLifecycleNow());

    expect($offer->status())->toBe(OfferStatus::Withdrawn)
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferWithdrawn::class)
        ->and(fn () => $offer->withdraw(offerLifecycleNow()))->toThrow(InvalidOfferTransition::class)
        ->and(fn () => $offer->changeStock(Stock::fromInt(1), offerLifecycleNow()))->toThrow(InvalidOfferTransition::class)
        ->and(fn () => $offer->changeDetails(Condition::Used, ShippingTime::fromInt(1), offerLifecycleNow()))->toThrow(InvalidOfferTransition::class);
})->with([OfferStatus::Draft, OfferStatus::Published, OfferStatus::Suspended]);

test('withdraw kasuje oczekującą zmianę ceny', function () {
    $offer = offerLifecycleOffer(OfferStatus::Published, pending: offerLifecyclePending());

    $offer->withdraw(offerLifecycleNow());

    expect($offer->pendingPriceChange())->toBeNull();
});

test('changeDetails: Draft i Published tak, Suspended nie', function () {
    $offer = offerLifecycleOffer(OfferStatus::Published);
    $offer->changeDetails(Condition::Used, ShippingTime::fromInt(9), offerLifecycleNow());

    expect($offer->condition())->toBe(Condition::Used)
        ->and($offer->shippingTime()->value())->toBe(9)
        ->and($offer->pendingEvents()[0]->shippingTime->value())->toBe(9)
        ->and(fn () => offerLifecycleOffer(OfferStatus::Suspended)->changeDetails(Condition::Used, ShippingTime::fromInt(9), offerLifecycleNow()))
        ->toThrow(InvalidOfferTransition::class);
});

test('allowedActionsFor zgodne z tabelą', function (OfferStatus $status, Actor $actor, bool $pending, int $stock, array $expected) {
    $offer = offerLifecycleOffer($status, $stock, $status === OfferStatus::Suspended ? offerLifecycleModeration() : null, $pending ? offerLifecyclePending() : null);

    expect($offer->allowedActionsFor($actor))->toBe($expected);
})->with(function () {
    $S = Actor::Seller;
    $M = Actor::Moderator;

    return [
        'draft seller, stan>0' => [OfferStatus::Draft, $S, false, 5, [OfferAction::Publish, OfferAction::ChangePrice, OfferAction::ChangeStock, OfferAction::ChangeDetails, OfferAction::Withdraw]],
        'draft seller, stan 0' => [OfferStatus::Draft, $S, false, 0, [OfferAction::ChangePrice, OfferAction::ChangeStock, OfferAction::ChangeDetails, OfferAction::Withdraw]],
        'draft moderator' => [OfferStatus::Draft, $M, false, 5, []],
        'draft seller, pending (z bazy) nadal może zmienić cenę' => [OfferStatus::Draft, $S, true, 5, [OfferAction::Publish, OfferAction::ChangePrice, OfferAction::ChangeStock, OfferAction::ChangeDetails, OfferAction::Withdraw]],
        'published seller, brak pending' => [OfferStatus::Published, $S, false, 5, [OfferAction::ChangePrice, OfferAction::ChangeStock, OfferAction::ChangeDetails, OfferAction::Withdraw]],
        'published seller, pending' => [OfferStatus::Published, $S, true, 5, [OfferAction::CancelPendingPriceChange, OfferAction::ChangeStock, OfferAction::ChangeDetails, OfferAction::Withdraw]],
        'published moderator, brak pending' => [OfferStatus::Published, $M, false, 5, [OfferAction::Suspend]],
        'published moderator, pending' => [OfferStatus::Published, $M, true, 5, [OfferAction::Suspend, OfferAction::ApprovePriceChange, OfferAction::RejectPriceChange]],
        'published seller, stan 0' => [OfferStatus::Published, $S, false, 0, [OfferAction::ChangePrice, OfferAction::ChangeStock, OfferAction::ChangeDetails, OfferAction::Withdraw]],
        'suspended seller, brak pending' => [OfferStatus::Suspended, $S, false, 5, [OfferAction::ChangeStock, OfferAction::Withdraw]],
        'suspended seller, pending' => [OfferStatus::Suspended, $S, true, 5, [OfferAction::CancelPendingPriceChange, OfferAction::ChangeStock, OfferAction::Withdraw]],
        'suspended moderator, brak pending' => [OfferStatus::Suspended, $M, false, 5, [OfferAction::Reinstate]],
        'suspended moderator, pending' => [OfferStatus::Suspended, $M, true, 5, [OfferAction::Reinstate, OfferAction::ApprovePriceChange, OfferAction::RejectPriceChange]],
        'withdrawn seller' => [OfferStatus::Withdrawn, $S, false, 5, []],
        'withdrawn moderator' => [OfferStatus::Withdrawn, $M, false, 5, []],
    ];
});

test('InvalidOfferTransition ma stabilny kod i opisuje status oraz akcję', function () {
    try {
        offerLifecycleOffer(OfferStatus::Withdrawn)->withdraw(offerLifecycleNow());
        $this->fail('Powinien rzucić wyjątek.');
    } catch (InvalidOfferTransition $e) {
        expect($e::CODE)->toBe('invalid_offer_transition')
            ->and($e->getMessage())->toContain('withdrawn')
            ->and($e->getMessage())->toContain('withdraw');
    }
});
