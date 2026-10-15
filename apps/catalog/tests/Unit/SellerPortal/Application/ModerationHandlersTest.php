<?php

use SellerPortal\Application\Moderation\ApprovePriceChange;
use SellerPortal\Application\Moderation\ApprovePriceChangeHandler;
use SellerPortal\Application\Moderation\ReinstateOffer;
use SellerPortal\Application\Moderation\ReinstateOfferHandler;
use SellerPortal\Application\Moderation\RejectPriceChange;
use SellerPortal\Application\Moderation\RejectPriceChangeHandler;
use SellerPortal\Application\Moderation\SuspendOffer;
use SellerPortal\Application\Moderation\SuspendOfferHandler;
use SellerPortal\Application\Offer\ChangeOfferPrice;
use SellerPortal\Application\Offer\ChangeOfferPriceHandler;
use SellerPortal\Application\Offer\PublishOffer;
use SellerPortal\Application\Offer\PublishOfferHandler;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeApproved;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeRejected;
use SellerPortal\Domain\Offer\Event\OfferReinstated;
use SellerPortal\Domain\Offer\Event\OfferSuspended;
use SellerPortal\Domain\Offer\NoPendingPriceChange;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferStatus;
use Tests\Unit\SellerPortal\InMemory\PortalWorld;

function moderationHandlersPublishedOffer(PortalWorld $w): OfferId
{
    $w->activeSeller(7);
    $id = $w->draft(7, priceCents: 10000);
    (new PublishOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new PublishOffer(7, $id->value()));
    $w->forgetDispatched();

    return $id;
}

test('moderator zawiesza i przywraca ofertę', function () {
    $w = new PortalWorld;
    $id = moderationHandlersPublishedOffer($w);

    (new SuspendOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))->handle(new SuspendOffer(900, $id->value(), 'Podejrzana cena'));
    expect($w->offers->get($id)->status())->toBe(OfferStatus::Suspended);

    (new ReinstateOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))->handle(new ReinstateOffer(900, $id->value()));

    expect($w->offers->get($id)->status())->toBe(OfferStatus::Published)
        ->and($w->dispatcher->classes())->toBe([OfferSuspended::class, OfferReinstated::class]);
});

test('moderator akceptuje zmianę ceny oczekującą na decyzję', function () {
    $w = new PortalWorld;
    $id = moderationHandlersPublishedOffer($w);
    (new ChangeOfferPriceHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new ChangeOfferPrice(7, $id->value(), 20000));
    $w->forgetDispatched();

    (new ApprovePriceChangeHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))->handle(new ApprovePriceChange(900, $id->value()));

    expect($w->offers->get($id)->price()->cents())->toBe(20000)
        ->and($w->offers->get($id)->pendingPriceChange())->toBeNull()
        ->and($w->dispatcher->classes())->toBe([OfferPriceChangeApproved::class]);
});

test('moderator odrzuca zmianę ceny, cena zostaje', function () {
    $w = new PortalWorld;
    $id = moderationHandlersPublishedOffer($w);
    (new ChangeOfferPriceHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new ChangeOfferPrice(7, $id->value(), 20000));
    $w->forgetDispatched();

    (new RejectPriceChangeHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))->handle(new RejectPriceChange(900, $id->value(), 'Za wysoka'));

    expect($w->offers->get($id)->price()->cents())->toBe(10000)
        ->and($w->dispatcher->classes())->toBe([OfferPriceChangeRejected::class]);
});

test('akceptacja bez oczekującej zmiany rzuca NoPendingPriceChange i nic nie dispatchuje', function () {
    $w = new PortalWorld;
    $id = moderationHandlersPublishedOffer($w);

    expect(fn () => (new ApprovePriceChangeHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))->handle(new ApprovePriceChange(900, $id->value())))
        ->toThrow(NoPendingPriceChange::class)
        ->and($w->dispatcher->dispatched)->toBe([]);
});
