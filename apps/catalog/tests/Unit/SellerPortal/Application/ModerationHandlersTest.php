<?php

use SellerPortal\Application\Moderation\ApprovePriceChange;
use SellerPortal\Application\Moderation\ApprovePriceChangeHandler;
use SellerPortal\Application\Moderation\ReinstateOffer;
use SellerPortal\Application\Moderation\ReinstateOfferHandler;
use SellerPortal\Application\Moderation\RejectPriceChange;
use SellerPortal\Application\Moderation\RejectPriceChangeHandler;
use SellerPortal\Application\Moderation\SuspendOffer;
use SellerPortal\Application\Moderation\SuspendOfferHandler;
use SellerPortal\Application\Offer\CancelPendingPriceChange;
use SellerPortal\Application\Offer\CancelPendingPriceChangeHandler;
use SellerPortal\Application\Offer\ChangeOfferPrice;
use SellerPortal\Application\Offer\ChangeOfferPriceHandler;
use SellerPortal\Application\Offer\ChangeOfferStock;
use SellerPortal\Application\Offer\ChangeOfferStockHandler;
use SellerPortal\Application\Offer\PublishOffer;
use SellerPortal\Application\Offer\PublishOfferHandler;
use SellerPortal\Application\Seller\BlockSeller;
use SellerPortal\Application\Seller\BlockSellerHandler;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeApproved;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeRejected;
use SellerPortal\Domain\Offer\Event\OfferReinstated;
use SellerPortal\Domain\Offer\Event\OfferSuspended;
use SellerPortal\Domain\Offer\NoPendingPriceChange;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferStatus;
use SellerPortal\Domain\Offer\PendingPriceChangeMismatch;
use SellerPortal\Domain\Seller\SellerCannotSell;
use SellerPortal\Domain\Shared\ConcurrencyConflict;
use Tests\Unit\SellerPortal\InMemory\PortalWorld;

function moderationHandlersPublishedOffer(PortalWorld $w): OfferId
{
    $w->activeSeller(7);
    $id = $w->draft(7, priceCents: 10000);
    (new PublishOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new PublishOffer(7, $id->value(), $w->version($id)));
    $w->forgetDispatched();

    return $id;
}

test('moderator zawiesza i przywraca ofertę', function () {
    $w = new PortalWorld;
    $id = moderationHandlersPublishedOffer($w);

    (new SuspendOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))->handle(new SuspendOffer(900, $id->value(), 'Podejrzana cena', $w->version($id)));
    expect($w->offers->get($id)->status())->toBe(OfferStatus::Suspended);

    (new ReinstateOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers, $w->sellers))->handle(new ReinstateOffer(900, $id->value(), $w->version($id)));

    expect($w->offers->get($id)->status())->toBe(OfferStatus::Published)
        ->and($w->dispatcher->classes())->toBe([OfferSuspended::class, OfferReinstated::class]);
});

test('moderator akceptuje zmianę ceny oczekującą na decyzję', function () {
    $w = new PortalWorld;
    $id = moderationHandlersPublishedOffer($w);
    (new ChangeOfferPriceHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new ChangeOfferPrice(7, $id->value(), 20000, $w->version($id)));
    $w->forgetDispatched();

    (new ApprovePriceChangeHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))->handle(new ApprovePriceChange(900, $id->value(), 20000, $w->version($id)));

    expect($w->offers->get($id)->price()->cents())->toBe(20000)
        ->and($w->offers->get($id)->pendingPriceChange())->toBeNull()
        ->and($w->dispatcher->classes())->toBe([OfferPriceChangeApproved::class]);
});

test('moderator odrzuca zmianę ceny, cena zostaje', function () {
    $w = new PortalWorld;
    $id = moderationHandlersPublishedOffer($w);
    (new ChangeOfferPriceHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new ChangeOfferPrice(7, $id->value(), 20000, $w->version($id)));
    $w->forgetDispatched();

    (new RejectPriceChangeHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))->handle(new RejectPriceChange(900, $id->value(), 'Za wysoka', 20000, $w->version($id)));

    expect($w->offers->get($id)->price()->cents())->toBe(10000)
        ->and($w->dispatcher->classes())->toBe([OfferPriceChangeRejected::class]);
});

test('akceptacja bez oczekującej zmiany rzuca NoPendingPriceChange i nic nie dispatchuje', function () {
    $w = new PortalWorld;
    $id = moderationHandlersPublishedOffer($w);

    expect(fn () => (new ApprovePriceChangeHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))->handle(new ApprovePriceChange(900, $id->value(), 20000, $w->version($id))))
        ->toThrow(NoPendingPriceChange::class)
        ->and($w->dispatcher->dispatched)->toBe([]);
});

test('akceptacja ceny innej niż zgłoszona rzuca PendingPriceChangeMismatch (przed konfliktem wersji)', function () {
    $w = new PortalWorld;
    $id = moderationHandlersPublishedOffer($w);
    $change = new ChangeOfferPriceHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers);
    $change->handle(new ChangeOfferPrice(7, $id->value(), 13100, $w->version($id)));
    $seenVersion = $w->version($id);
    (new CancelPendingPriceChangeHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new CancelPendingPriceChange(7, $id->value(), $w->version($id)));
    $change->handle(new ChangeOfferPrice(7, $id->value(), 90000, $w->version($id)));
    $w->forgetDispatched();

    // Moderator klika „Akceptuj” w wierszu, który pokazywał 131 zł i starą wersję.
    expect(fn () => (new ApprovePriceChangeHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))->handle(new ApprovePriceChange(900, $id->value(), 13100, $seenVersion)))
        ->toThrow(PendingPriceChangeMismatch::class)
        ->and(fn () => (new RejectPriceChangeHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))->handle(new RejectPriceChange(900, $id->value(), 'Za wysoka', 13100, $seenVersion)))
        ->toThrow(PendingPriceChangeMismatch::class)
        ->and($w->offers->get($id)->price()->cents())->toBe(10000)
        ->and($w->offers->get($id)->pendingPriceChange()->price()->cents())->toBe(90000)
        ->and($w->dispatcher->dispatched)->toBe([]);
});

test('decyzja moderatora na nieaktualnej wersji rzuca ConcurrencyConflict i nic nie zmienia', function () {
    $w = new PortalWorld;
    $id = moderationHandlersPublishedOffer($w);
    $seenVersion = $w->version($id);
    (new ChangeOfferStockHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new ChangeOfferStock(7, $id->value(), 9, $seenVersion));
    $w->forgetDispatched();

    expect(fn () => (new SuspendOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))->handle(new SuspendOffer(900, $id->value(), 'Podejrzana cena', $seenVersion)))
        ->toThrow(ConcurrencyConflict::class)
        ->and($w->offers->get($id)->status())->toBe(OfferStatus::Published)
        ->and($w->dispatcher->dispatched)->toBe([]);
});

test('moderator nie przywróci oferty zablokowanego sprzedawcy', function () {
    $w = new PortalWorld;
    $id = moderationHandlersPublishedOffer($w);
    (new SuspendOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))->handle(new SuspendOffer(900, $id->value(), 'Podejrzana cena', $w->version($id)));
    $sellerId = $w->offers->get($id)->sellerId();
    (new BlockSellerHandler($w->transactions, $w->clock, $w->dispatcher, $w->sellers))->handle(new BlockSeller($sellerId->value(), 900, 'Oszustwo'));
    $w->forgetDispatched();

    expect(fn () => (new ReinstateOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers, $w->sellers))->handle(new ReinstateOffer(900, $id->value(), $w->version($id))))
        ->toThrow(SellerCannotSell::class)
        ->and($w->offers->get($id)->status())->toBe(OfferStatus::Suspended)
        ->and($w->dispatcher->dispatched)->toBe([]);
});
