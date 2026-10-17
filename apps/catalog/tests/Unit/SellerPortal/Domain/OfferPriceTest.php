<?php

use SellerPortal\Domain\Offer\Condition;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeApproved;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeCancelled;
use SellerPortal\Domain\Offer\Event\OfferPriceChanged;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeRejected;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeRequested;
use SellerPortal\Domain\Offer\InvalidOfferTransition;
use SellerPortal\Domain\Offer\NoPendingPriceChange;
use SellerPortal\Domain\Offer\Offer;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferStatus;
use SellerPortal\Domain\Offer\PendingPriceChange;
use SellerPortal\Domain\Offer\PendingPriceChangeMismatch;
use SellerPortal\Domain\Offer\PriceChangeAlreadyPending;
use SellerPortal\Domain\Offer\PriceChangeDecision;
use SellerPortal\Domain\Offer\PriceChangeLimitExceeded;
use SellerPortal\Domain\Offer\ProductId;
use SellerPortal\Domain\Offer\ShippingTime;
use SellerPortal\Domain\Offer\Stock;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Reason;
use SharedKernel\Currency;
use SharedKernel\CurrencyMismatch;
use SharedKernel\Money;

function offerPriceNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-02-01 10:00:00');
}

/**
 * @param  list<DateTimeImmutable>  $recent
 */
function offerPriceOffer(OfferStatus $status = OfferStatus::Published, int $cents = 10000, array $recent = [], ?PendingPriceChange $pending = null): Offer
{
    return Offer::reconstitute(
        OfferId::fromInt(10), SellerId::fromInt(1), ProductId::fromInt(99), Money::fromCents($cents), Stock::fromInt(5),
        Condition::New, ShippingTime::fromInt(3), $status, null, $pending, $recent, [], 4,
    );
}

/** @return list<DateTimeImmutable> */
function offerPriceHoursAgo(int ...$hours): array
{
    return array_map(static fn (int $h): DateTimeImmutable => offerPriceNow()->modify("-{$h} hours"), $hours);
}

test('Published: zmiana do 30% wchodzi od razu, zapisuje się w oknie i rodzi OfferPriceChanged', function () {
    $offer = offerPriceOffer();

    $decision = $offer->changePrice(Money::fromCents(13000), offerPriceNow());

    expect($decision)->toBe(PriceChangeDecision::AppliedNow)
        ->and($offer->price()->cents())->toBe(13000)
        ->and($offer->pendingPriceChange())->toBeNull()
        ->and($offer->recentPriceChanges())->toEqual([offerPriceNow()])
        ->and($offer->pendingEvents())->toHaveCount(1)
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferPriceChanged::class)
        ->and($offer->pendingEvents()[0]->old->cents())->toBe(10000)
        ->and($offer->pendingEvents()[0]->new->cents())->toBe(13000);
});

test('Published: zmiana powyżej 30% czeka, cena zostaje stara, liczy się do limitu', function () {
    $offer = offerPriceOffer();

    $decision = $offer->changePrice(Money::fromCents(13001), offerPriceNow());

    expect($decision)->toBe(PriceChangeDecision::AwaitingApproval)
        ->and($offer->price()->cents())->toBe(10000)
        ->and($offer->pendingPriceChange()->price()->cents())->toBe(13001)
        ->and($offer->pendingPriceChange()->requestedAt())->toEqual(offerPriceNow())
        ->and($offer->recentPriceChanges())->toEqual([offerPriceNow()])
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferPriceChangeRequested::class)
        ->and($offer->pendingEvents()[0]->current->cents())->toBe(10000)
        ->and($offer->pendingEvents()[0]->proposed->cents())->toBe(13001);
});

test('szósta zmiana w 24 h rzuca PriceChangeLimitExceeded', function () {
    $offer = offerPriceOffer();
    $price = 10000;

    foreach (range(5, 1) as $hoursAgo) {
        $price = (int) round($price * 1.01);
        $offer->changePrice(Money::fromCents($price), offerPriceNow()->modify("-{$hoursAgo} hours"));
    }

    expect(fn () => $offer->changePrice(Money::fromCents((int) round($price * 1.01)), offerPriceNow()))
        ->toThrow(PriceChangeLimitExceeded::class)
        ->and($offer->recentPriceChanges())->toHaveCount(5);
});

test('zmiana sprzed 24 h i 1 s nie liczy się do limitu, a stare wpisy są przycinane', function () {
    $offer = offerPriceOffer(recent: [
        ...offerPriceHoursAgo(1, 2, 3, 4),
        offerPriceNow()->modify('-24 hours -1 second'),
    ]);

    $offer->changePrice(Money::fromCents(10100), offerPriceNow());

    expect($offer->price()->cents())->toBe(10100)
        ->and($offer->recentPriceChanges())->toHaveCount(5);
});

test('zgłoszenie do akceptacji liczy się do limitu', function () {
    $offer = offerPriceOffer(recent: offerPriceHoursAgo(1, 2, 3, 4));

    $offer->changePrice(Money::fromCents(90000), offerPriceNow());
    $offer->cancelPendingPriceChange(offerPriceNow());

    expect(fn () => $offer->changePrice(Money::fromCents(10100), offerPriceNow()))->toThrow(PriceChangeLimitExceeded::class);
});

test('przy oczekującej zmianie kolejna rzuca PriceChangeAlreadyPending (przed limitem)', function () {
    $offer = offerPriceOffer(recent: offerPriceHoursAgo(1, 2, 3, 4, 5), pending: new PendingPriceChange(Money::fromCents(20000), offerPriceNow()));

    expect(fn () => $offer->changePrice(Money::fromCents(10100), offerPriceNow()))->toThrow(PriceChangeAlreadyPending::class)
        ->and(PriceChangeAlreadyPending::CODE)->toBe('price_change_pending');
});

test('approve ustawia nową cenę, czyści pending i nie dopisuje do limitu', function () {
    $offer = offerPriceOffer();
    $offer->changePrice(Money::fromCents(20000), offerPriceNow());
    $offer->releaseEvents();

    $offer->approvePriceChange(ModeratorId::fromInt(2), Money::fromCents(20000), offerPriceNow());

    expect($offer->price()->cents())->toBe(20000)
        ->and($offer->pendingPriceChange())->toBeNull()
        ->and($offer->recentPriceChanges())->toHaveCount(1)
        ->and($offer->pendingEvents())->toHaveCount(1)
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferPriceChangeApproved::class)
        ->and($offer->pendingEvents()[0]->old->cents())->toBe(10000)
        ->and($offer->pendingEvents()[0]->new->cents())->toBe(20000)
        ->and($offer->pendingEvents()[0]->moderatorId->value())->toBe(2);
});

test('approve działa też na zawieszonej ofercie', function () {
    $offer = offerPriceOffer(OfferStatus::Suspended, pending: new PendingPriceChange(Money::fromCents(20000), offerPriceNow()));

    $offer->approvePriceChange(ModeratorId::fromInt(2), Money::fromCents(20000), offerPriceNow());

    expect($offer->price()->cents())->toBe(20000);
});

test('reject zostawia starą cenę, czyści pending i niesie powód', function () {
    $offer = offerPriceOffer();
    $offer->changePrice(Money::fromCents(20000), offerPriceNow());
    $offer->releaseEvents();

    $offer->rejectPriceChange(ModeratorId::fromInt(2), Money::fromCents(20000), Reason::fromString('Za wysoka'), offerPriceNow());

    expect($offer->price()->cents())->toBe(10000)
        ->and($offer->pendingPriceChange())->toBeNull()
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferPriceChangeRejected::class)
        ->and($offer->pendingEvents()[0]->proposed->cents())->toBe(20000)
        ->and($offer->pendingEvents()[0]->reason->value())->toBe('Za wysoka');
});

test('approve i reject bez oczekującej zmiany rzucają NoPendingPriceChange', function () {
    $offer = offerPriceOffer();

    expect(fn () => $offer->approvePriceChange(ModeratorId::fromInt(2), Money::fromCents(20000), offerPriceNow()))->toThrow(NoPendingPriceChange::class)
        ->and(fn () => $offer->rejectPriceChange(ModeratorId::fromInt(2), Money::fromCents(20000), Reason::fromString('x'), offerPriceNow()))->toThrow(NoPendingPriceChange::class)
        ->and(NoPendingPriceChange::CODE)->toBe('no_pending_price_change');
});

test('approve i reject innej ceny niż oczekująca rzucają PendingPriceChangeMismatch i nic nie zmieniają', function () {
    // Moderator widział 13 100 zł, a sprzedawca w międzyczasie anulował i zgłosił 90 000 zł.
    $offer = offerPriceOffer(pending: new PendingPriceChange(Money::fromCents(90000), offerPriceNow()));

    expect(fn () => $offer->approvePriceChange(ModeratorId::fromInt(2), Money::fromCents(13100), offerPriceNow()))->toThrow(PendingPriceChangeMismatch::class)
        ->and(fn () => $offer->rejectPriceChange(ModeratorId::fromInt(2), Money::fromCents(13100), Reason::fromString('Za wysoka'), offerPriceNow()))->toThrow(PendingPriceChangeMismatch::class)
        ->and($offer->price()->cents())->toBe(10000)
        ->and($offer->pendingPriceChange()->price()->cents())->toBe(90000)
        ->and($offer->pendingEvents())->toBe([])
        ->and(PendingPriceChangeMismatch::CODE)->toBe('pending_price_changed');
});

test('cancel tylko gdy pending, inaczej NoPendingPriceChange', function () {
    $offer = offerPriceOffer();

    expect(fn () => $offer->cancelPendingPriceChange(offerPriceNow()))->toThrow(NoPendingPriceChange::class);

    $offer->changePrice(Money::fromCents(20000), offerPriceNow());
    $offer->releaseEvents();
    $offer->cancelPendingPriceChange(offerPriceNow());

    expect($offer->pendingPriceChange())->toBeNull()
        ->and($offer->price()->cents())->toBe(10000)
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferPriceChangeCancelled::class)
        ->and($offer->pendingEvents()[0]->proposed->cents())->toBe(20000);
});

test('w Draft zmiana o 90% wchodzi od razu i nie liczy się do limitu', function () {
    $offer = offerPriceOffer(OfferStatus::Draft, recent: offerPriceHoursAgo(1, 2, 3, 4, 5));

    $decision = $offer->changePrice(Money::fromCents(19000), offerPriceNow());

    expect($decision)->toBe(PriceChangeDecision::AppliedNow)
        ->and($offer->price()->cents())->toBe(19000)
        ->and($offer->recentPriceChanges())->toEqual(offerPriceHoursAgo(1, 2, 3, 4, 5))
        ->and($offer->pendingEvents()[0])->toBeInstanceOf(OfferPriceChanged::class);
});

test('ta sama cena to no-op: bez zdarzenia i bez wpisu do limitu', function (OfferStatus $status) {
    $offer = offerPriceOffer($status);

    $decision = $offer->changePrice(Money::fromCents(10000), offerPriceNow());

    expect($decision)->toBe(PriceChangeDecision::AppliedNow)
        ->and($offer->pendingEvents())->toBe([])
        ->and($offer->recentPriceChanges())->toBe([]);
})->with([OfferStatus::Draft, OfferStatus::Published]);

test('zmiana ceny w Suspended i Withdrawn rzuca InvalidOfferTransition', function (OfferStatus $status) {
    expect(fn () => offerPriceOffer($status)->changePrice(Money::fromCents(10100), offerPriceNow()))->toThrow(InvalidOfferTransition::class);
})->with([OfferStatus::Suspended, OfferStatus::Withdrawn]);

test('zmiana waluty rzuca CurrencyMismatch (Published i Draft)', function (OfferStatus $status) {
    $offer = offerPriceOffer($status);

    expect(fn () => $offer->changePrice(Money::fromCents(10000, Currency::EUR), offerPriceNow()))->toThrow(CurrencyMismatch::class)
        ->and($offer->price()->currency())->toBe(Currency::PLN)
        ->and($offer->recentPriceChanges())->toBe([]);
})->with([OfferStatus::Draft, OfferStatus::Published]);
