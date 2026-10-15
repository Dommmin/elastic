<?php

use SellerPortal\Application\Offer\CancelPendingPriceChange;
use SellerPortal\Application\Offer\CancelPendingPriceChangeHandler;
use SellerPortal\Application\Offer\ChangeOfferDetails;
use SellerPortal\Application\Offer\ChangeOfferDetailsHandler;
use SellerPortal\Application\Offer\ChangeOfferPrice;
use SellerPortal\Application\Offer\ChangeOfferPriceHandler;
use SellerPortal\Application\Offer\ChangeOfferStock;
use SellerPortal\Application\Offer\ChangeOfferStockHandler;
use SellerPortal\Application\Offer\DraftOffer;
use SellerPortal\Application\Offer\DraftOfferHandler;
use SellerPortal\Application\Offer\OfferNotOwned;
use SellerPortal\Application\Offer\PublishOffer;
use SellerPortal\Application\Offer\PublishOfferHandler;
use SellerPortal\Application\Offer\WithdrawOffer;
use SellerPortal\Application\Offer\WithdrawOfferHandler;
use SellerPortal\Application\ProductNotFound;
use SellerPortal\Application\Seller\BlockSeller;
use SellerPortal\Application\Seller\BlockSellerHandler;
use SellerPortal\Domain\Offer\Event\OfferDrafted;
use SellerPortal\Domain\Offer\Event\OfferPublished;
use SellerPortal\Domain\Offer\LiveOfferAlreadyExists;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferStatus;
use SellerPortal\Domain\Offer\PriceChangeDecision;
use SellerPortal\Domain\Seller\SellerCannotSell;
use SellerPortal\Domain\Seller\SellerNotFound;
use SellerPortal\Domain\Shared\ConcurrencyConflict;
use SellerPortal\Domain\Shared\InvalidValue;
use Tests\Unit\SellerPortal\InMemory\PortalWorld;

function offerHandlersPublish(PortalWorld $w, int $userId, OfferId $offerId): void
{
    (new PublishOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new PublishOffer($userId, $offerId->value(), $w->version($offerId)));
}

function offerHandlersChangePrice(PortalWorld $w, int $userId, OfferId $offerId, int $cents): PriceChangeDecision
{
    return (new ChangeOfferPriceHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new ChangeOfferPrice($userId, $offerId->value(), $cents, $w->version($offerId)));
}

test('szkic zapisuje ofertę i dispatchuje OfferDrafted', function () {
    $w = new PortalWorld;
    $w->activeSeller(7);

    $id = $w->draft(7);

    expect($w->offers->get($id)->status())->toBe(OfferStatus::Draft)
        ->and($w->dispatcher->classes())->toContain(OfferDrafted::class);
});

test('szkic na nieistniejący produkt rzuca ProductNotFound', function () {
    $w = new PortalWorld;
    $w->activeSeller(7);

    expect(fn () => $w->draft(7, productId: 999))->toThrow(ProductNotFound::class);
});

test('szkic gdy sprzedawca ma żywą ofertę na produkt rzuca LiveOfferAlreadyExists', function () {
    $w = new PortalWorld;
    $w->activeSeller(7);
    $w->draft(7, productId: 1);

    expect(fn () => $w->draft(7, productId: 1))->toThrow(LiveOfferAlreadyExists::class);
});

test('wycofana oferta nie blokuje nowego szkicu na ten sam produkt', function () {
    $w = new PortalWorld;
    $w->activeSeller(7);
    $id = $w->draft(7, productId: 1);
    (new WithdrawOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new WithdrawOffer(7, $id->value(), $w->version($id)));

    expect($w->draft(7, productId: 1)->value())->not->toBe($id->value());
});

test('szkic użytkownika bez konta sprzedawcy rzuca SellerNotFound', function () {
    $w = new PortalWorld;

    expect(fn () => $w->draft(7))->toThrow(SellerNotFound::class);
});

test('szkic nieaktywnego sprzedawcy rzuca SellerCannotSell', function () {
    $w = new PortalWorld;
    $sellerId = $w->activeSeller(7);
    (new BlockSellerHandler($w->transactions, $w->clock, $w->dispatcher, $w->sellers))->handle(new BlockSeller($sellerId->value(), 9, 'Oszustwo'));

    expect(fn () => $w->draft(7))->toThrow(SellerCannotSell::class);
});

test('nieznany stan egzemplarza w komendzie to InvalidValue', function () {
    $w = new PortalWorld;
    $w->activeSeller(7);

    expect(fn () => (new DraftOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->sellers, $w->offers, $w->catalog))
        ->handle(new DraftOffer(7, 1, 10000, 5, 'zepsuty', 3)))->toThrow(InvalidValue::class);
});

test('handler dispatchuje zdarzenia po zapisie i czyści agregat', function () {
    $w = new PortalWorld;
    $w->activeSeller(7);
    $id = $w->draft(7);
    $w->forgetDispatched();

    offerHandlersPublish($w, 7, $id);

    expect($w->dispatcher->classes())->toBe([OfferPublished::class])
        ->and($w->offers->get($id)->status())->toBe(OfferStatus::Published)
        ->and($w->offers->get($id)->version())->toBe(2)
        ->and($w->offers->get($id)->pendingEvents())->toBe([]);
});

test('cudza oferta rzuca OfferNotOwned i nic nie zmienia', function () {
    $w = new PortalWorld;
    $w->activeSeller(7);
    $w->activeSeller(8);
    $id = $w->draft(7);
    $w->forgetDispatched();

    expect(fn () => offerHandlersPublish($w, 8, $id))->toThrow(OfferNotOwned::class)
        ->and($w->offers->get($id)->status())->toBe(OfferStatus::Draft)
        ->and($w->dispatcher->dispatched)->toBe([]);
});

test('zablokowany sprzedawca może wycofać ofertę, ale nie zmienić ceny', function () {
    $w = new PortalWorld;
    $sellerId = $w->activeSeller(7);
    $id = $w->draft(7);
    offerHandlersPublish($w, 7, $id);
    (new BlockSellerHandler($w->transactions, $w->clock, $w->dispatcher, $w->sellers))->handle(new BlockSeller($sellerId->value(), 9, 'Oszustwo'));

    expect(fn () => offerHandlersChangePrice($w, 7, $id, 10100))->toThrow(SellerCannotSell::class);

    (new WithdrawOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new WithdrawOffer(7, $id->value(), $w->version($id)));

    expect($w->offers->get($id)->status())->toBe(OfferStatus::Withdrawn);
});

test('zablokowany sprzedawca nie opublikuje ani nie zmieni stanu i szczegółów', function () {
    $w = new PortalWorld;
    $sellerId = $w->activeSeller(7);
    $id = $w->draft(7);
    (new BlockSellerHandler($w->transactions, $w->clock, $w->dispatcher, $w->sellers))->handle(new BlockSeller($sellerId->value(), 9, 'Oszustwo'));

    expect(fn () => offerHandlersPublish($w, 7, $id))->toThrow(SellerCannotSell::class)
        ->and(fn () => (new ChangeOfferStockHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new ChangeOfferStock(7, $id->value(), 9, $w->version($id))))->toThrow(SellerCannotSell::class)
        ->and(fn () => (new ChangeOfferDetailsHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new ChangeOfferDetails(7, $id->value(), 'used', 5, $w->version($id))))->toThrow(SellerCannotSell::class);
});

test('ChangeOfferPrice zwraca AppliedNow dla małej zmiany i AwaitingApproval dla +50%', function () {
    $w = new PortalWorld;
    $w->activeSeller(7);
    $id = $w->draft(7, priceCents: 10000);
    offerHandlersPublish($w, 7, $id);

    $small = offerHandlersChangePrice($w, 7, $id, 11000);
    $big = offerHandlersChangePrice($w, 7, $id, 16500);

    expect($small)->toBe(PriceChangeDecision::AppliedNow)
        ->and($big)->toBe(PriceChangeDecision::AwaitingApproval)
        ->and($w->offers->get($id)->price()->cents())->toBe(11000)
        ->and($w->offers->get($id)->pendingPriceChange()->price()->cents())->toBe(16500);
});

test('anulowanie oczekującej zmiany ceny czyści ją', function () {
    $w = new PortalWorld;
    $w->activeSeller(7);
    $id = $w->draft(7);
    offerHandlersPublish($w, 7, $id);
    offerHandlersChangePrice($w, 7, $id, 20000);

    (new CancelPendingPriceChangeHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new CancelPendingPriceChange(7, $id->value(), $w->version($id)));

    expect($w->offers->get($id)->pendingPriceChange())->toBeNull();
});

test('zmiana stanu i szczegółów zapisuje się w ofercie', function () {
    $w = new PortalWorld;
    $w->activeSeller(7);
    $id = $w->draft(7);

    (new ChangeOfferStockHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new ChangeOfferStock(7, $id->value(), 42, $w->version($id)));
    (new ChangeOfferDetailsHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new ChangeOfferDetails(7, $id->value(), 'used', 9, $w->version($id)));

    $offer = $w->offers->get($id);

    expect($offer->stock()->value())->toBe(42)
        ->and($offer->condition()->value)->toBe('used')
        ->and($offer->shippingTime()->value())->toBe(9);
});

test('nieudany zapis (konflikt wersji) nie dispatchuje zdarzeń', function () {
    $w = new PortalWorld;
    $w->activeSeller(7);
    $id = $w->draft(7);
    $w->forgetDispatched();

    $stale = $w->offers->get($id);
    offerHandlersPublish($w, 7, $id);
    $w->forgetDispatched();

    expect(fn () => $w->offers->save($stale))->toThrow(ConcurrencyConflict::class)
        ->and($w->dispatcher->dispatched)->toBe([]);
});

test('komenda z nieaktualną wersją (druga karta) rzuca ConcurrencyConflict i nic nie zmienia', function (Closure $command) {
    $w = new PortalWorld;
    $w->activeSeller(7);
    $id = $w->draft(7);
    offerHandlersPublish($w, 7, $id);
    $stale = $w->version($id);
    // Pierwsza karta zapisuje stan; druga wciąż pokazuje starą wersję.
    (new ChangeOfferStockHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new ChangeOfferStock(7, $id->value(), 9, $stale));
    $before = $w->offers->get($id);
    $w->forgetDispatched();

    expect(fn () => $command($w, $id, $stale))->toThrow(ConcurrencyConflict::class)
        ->and($w->offers->get($id))->toEqual($before)
        ->and($w->dispatcher->dispatched)->toBe([]);
})->with([
    'stan' => fn (PortalWorld $w, OfferId $id, int $v) => (new ChangeOfferStockHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new ChangeOfferStock(7, $id->value(), 1, $v)),
    'szczegóły' => fn (PortalWorld $w, OfferId $id, int $v) => (new ChangeOfferDetailsHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new ChangeOfferDetails(7, $id->value(), 'used', 5, $v)),
    'cena' => fn (PortalWorld $w, OfferId $id, int $v) => (new ChangeOfferPriceHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new ChangeOfferPrice(7, $id->value(), 10100, $v)),
    'wycofanie' => fn (PortalWorld $w, OfferId $id, int $v) => (new WithdrawOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new WithdrawOffer(7, $id->value(), $v)),
]);
