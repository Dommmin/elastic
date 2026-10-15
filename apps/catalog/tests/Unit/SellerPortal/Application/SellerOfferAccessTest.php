<?php

use SellerPortal\Application\Offer\PublishOffer;
use SellerPortal\Application\Offer\PublishOfferHandler;
use SellerPortal\Domain\Offer\Actor;
use SellerPortal\Domain\Offer\Offer;
use SellerPortal\Domain\Offer\OfferAction;
use SellerPortal\Domain\Seller\Seller;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Reason;
use SellerPortal\Domain\Shared\UserId;
use Tests\Unit\SellerPortal\InMemory\PortalWorld;

/**
 * @return array{0: PortalWorld, 1: Seller, 2: Offer}
 */
function sellerOfferAccessPublished(): array
{
    $w = new PortalWorld;
    $w->activeSeller(7);
    $id = $w->draft(7);
    (new PublishOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new PublishOffer(7, $id->value(), $w->version($id)));

    [$seller, $offer] = $w->access->forUser(UserId::fromInt(7), $id);

    return [$w, $seller, $offer];
}

test('aktywny sprzedawca dostaje pełną listę akcji z agregatu', function () {
    [$w, $seller, $offer] = sellerOfferAccessPublished();

    expect($sellerActions = $w->access->sellerActions($seller, $offer))
        ->toBe([OfferAction::ChangePrice, OfferAction::ChangeStock, OfferAction::ChangeDetails, OfferAction::Withdraw])
        ->and($sellerActions)->toBe($offer->allowedActionsFor(Actor::Seller));
});

test('zablokowany sprzedawca z opublikowaną ofertą może tylko wycofać', function () {
    [$w, $seller, $offer] = sellerOfferAccessPublished();
    $seller->block(ModeratorId::fromInt(900), Reason::fromString('Skargi'), $w->clock->now());

    expect($w->access->sellerActions($seller, $offer))->toBe([OfferAction::Withdraw]);
});

test('zablokowany sprzedawca z wycofaną ofertą nie ma żadnych akcji', function () {
    [$w, $seller, $offer] = sellerOfferAccessPublished();
    $offer->withdraw($w->clock->now());
    $seller->block(ModeratorId::fromInt(900), Reason::fromString('Skargi'), $w->clock->now());

    expect($w->access->sellerActions($seller, $offer))->toBe([]);
});
