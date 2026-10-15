<?php

use SellerPortal\Domain\Offer\Condition;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferStatus;
use SellerPortal\Domain\Offer\ProductId;
use SellerPortal\Domain\Offer\ShippingTime;
use SellerPortal\Domain\Offer\Stock;
use SellerPortal\Domain\Seller\City;
use SellerPortal\Domain\Seller\Event\SellerApproved;
use SellerPortal\Domain\Seller\Event\SellerBlocked;
use SellerPortal\Domain\Seller\Event\SellerRegistered;
use SellerPortal\Domain\Seller\Event\SellerRejected;
use SellerPortal\Domain\Seller\Event\SellerUnblocked;
use SellerPortal\Domain\Seller\InvalidSellerTransition;
use SellerPortal\Domain\Seller\Seller;
use SellerPortal\Domain\Seller\SellerCannotSell;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Seller\SellerName;
use SellerPortal\Domain\Seller\SellerStatus;
use SellerPortal\Domain\Shared\InvalidValue;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Money;
use SellerPortal\Domain\Shared\Reason;
use SellerPortal\Domain\Shared\UserId;

function sellerTestNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-01-01 12:00:00');
}

function pendingSellerFixture(): Seller
{
    $seller = Seller::register(SellerId::fromInt(1), UserId::fromInt(7), SellerName::fromString('Sklep Testowy'), City::fromString('Kraków'), sellerTestNow());
    $seller->releaseEvents();

    return $seller;
}

function sellerFixtureIn(SellerStatus $status): Seller
{
    return Seller::reconstitute(
        SellerId::fromInt(1),
        UserId::fromInt(7),
        SellerName::fromString('Sklep Testowy'),
        City::fromString('Kraków'),
        $status,
        null,
        3,
    );
}

test('SellerName i City przycinają i pilnują zakresów', function () {
    expect(SellerName::fromString('  Sklep  ')->value())->toBe('Sklep')
        ->and(fn () => SellerName::fromString('ab'))->toThrow(InvalidValue::class)
        ->and(fn () => SellerName::fromString(str_repeat('a', 121)))->toThrow(InvalidValue::class)
        ->and(City::fromString(' Łódź ')->value())->toBe('Łódź')
        ->and(fn () => City::fromString('   '))->toThrow(InvalidValue::class)
        ->and(fn () => City::fromString(str_repeat('a', 121)))->toThrow(InvalidValue::class)
        ->and(fn () => SellerId::fromInt(0))->toThrow(InvalidValue::class);
});

test('rejestracja daje Pending i SellerRegistered', function () {
    $seller = Seller::register(SellerId::fromInt(1), UserId::fromInt(7), SellerName::fromString('Sklep Testowy'), City::fromString('Kraków'), sellerTestNow());

    expect($seller->status())->toBe(SellerStatus::Pending)
        ->and($seller->version())->toBe(0)
        ->and($seller->statusReason())->toBeNull()
        ->and($seller->pendingEvents())->toHaveCount(1)
        ->and($seller->pendingEvents()[0])->toBeInstanceOf(SellerRegistered::class)
        ->and($seller->pendingEvents()[0]->userId->value())->toBe(7)
        ->and($seller->pendingEvents()[0]->occurredAt())->toEqual(sellerTestNow());
});

test('approve z Pending → Active, SellerApproved z moderatorem', function () {
    $seller = pendingSellerFixture();
    $seller->approve(ModeratorId::fromInt(9), sellerTestNow());

    expect($seller->status())->toBe(SellerStatus::Active)
        ->and($seller->pendingEvents())->toHaveCount(1)
        ->and($seller->pendingEvents()[0])->toBeInstanceOf(SellerApproved::class)
        ->and($seller->pendingEvents()[0]->moderatorId->value())->toBe(9);
});

test('reject z Pending → Rejected z powodem; Rejected jest końcowy', function () {
    $seller = pendingSellerFixture();
    $seller->reject(ModeratorId::fromInt(9), Reason::fromString('brak danych'), sellerTestNow());

    expect($seller->status())->toBe(SellerStatus::Rejected)
        ->and($seller->statusReason()->value())->toBe('brak danych')
        ->and($seller->pendingEvents()[0])->toBeInstanceOf(SellerRejected::class)
        ->and($seller->pendingEvents()[0]->reason->value())->toBe('brak danych')
        ->and(fn () => $seller->approve(ModeratorId::fromInt(9), sellerTestNow()))->toThrow(InvalidSellerTransition::class)
        ->and(fn () => $seller->unblock(ModeratorId::fromInt(9), sellerTestNow()))->toThrow(InvalidSellerTransition::class);
});

test('block tylko z Active; unblock tylko z Blocked', function () {
    $mod = ModeratorId::fromInt(9);
    $reason = Reason::fromString('skargi');

    expect(fn () => pendingSellerFixture()->block($mod, $reason, sellerTestNow()))->toThrow(InvalidSellerTransition::class)
        ->and(fn () => sellerFixtureIn(SellerStatus::Active)->unblock($mod, sellerTestNow()))->toThrow(InvalidSellerTransition::class);

    $seller = sellerFixtureIn(SellerStatus::Active);
    $seller->block($mod, $reason, sellerTestNow());

    expect($seller->status())->toBe(SellerStatus::Blocked)
        ->and($seller->statusReason()->value())->toBe('skargi')
        ->and($seller->pendingEvents()[0])->toBeInstanceOf(SellerBlocked::class);

    $seller->unblock($mod, sellerTestNow());

    expect($seller->status())->toBe(SellerStatus::Active)
        ->and($seller->statusReason())->toBeNull()
        ->and($seller->pendingEvents()[1])->toBeInstanceOf(SellerUnblocked::class);
});

test('niedozwolone przejście niesie stały kod i stan', function () {
    try {
        pendingSellerFixture()->unblock(ModeratorId::fromInt(9), sellerTestNow());
        $this->fail('Oczekiwano wyjątku.');
    } catch (InvalidSellerTransition $e) {
        expect($e::CODE)->toBe('invalid_seller_transition')
            ->and($e->getMessage())->toContain('pending')
            ->and($e->getMessage())->toContain('unblock');
    }
});

test('canSell tylko dla Active', function () {
    expect(sellerFixtureIn(SellerStatus::Active)->canSell())->toBeTrue()
        ->and(sellerFixtureIn(SellerStatus::Pending)->canSell())->toBeFalse()
        ->and(sellerFixtureIn(SellerStatus::Rejected)->canSell())->toBeFalse()
        ->and(sellerFixtureIn(SellerStatus::Blocked)->canSell())->toBeFalse();
});

test('reconstitute nie emituje zdarzeń i zachowuje wersję', function () {
    $seller = sellerFixtureIn(SellerStatus::Active);

    expect($seller->pendingEvents())->toBe([])
        ->and($seller->version())->toBe(3);
});

test('draftOffer deleguje do Offer::draft dla aktywnego sprzedawcy', function () {
    $offer = sellerFixtureIn(SellerStatus::Active)->draftOffer(
        OfferId::fromInt(10), ProductId::fromInt(99), Money::fromCents(10000), Stock::fromInt(5),
        Condition::New, ShippingTime::fromInt(2), sellerTestNow(),
    );

    expect($offer->sellerId()->value())->toBe(1)
        ->and($offer->status())->toBe(OfferStatus::Draft);
});

test('draftOffer rzuca SellerCannotSell dla nieaktywnego', function () {
    expect(fn () => sellerFixtureIn(SellerStatus::Pending)->draftOffer(
        OfferId::fromInt(10), ProductId::fromInt(99), Money::fromCents(10000), Stock::fromInt(5),
        Condition::New, ShippingTime::fromInt(2), sellerTestNow(),
    ))->toThrow(SellerCannotSell::class);
});
