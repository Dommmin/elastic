<?php

use SellerPortal\Application\Seller\ApproveSeller;
use SellerPortal\Application\Seller\ApproveSellerHandler;
use SellerPortal\Application\Seller\BlockSeller;
use SellerPortal\Application\Seller\BlockSellerHandler;
use SellerPortal\Application\Seller\RegisterSeller;
use SellerPortal\Application\Seller\RegisterSellerHandler;
use SellerPortal\Application\Seller\RejectSeller;
use SellerPortal\Application\Seller\RejectSellerHandler;
use SellerPortal\Application\Seller\UnblockSeller;
use SellerPortal\Application\Seller\UnblockSellerHandler;
use SellerPortal\Application\SellerAlreadyRegistered;
use SellerPortal\Domain\Seller\Event\SellerApproved;
use SellerPortal\Domain\Seller\Event\SellerBlocked;
use SellerPortal\Domain\Seller\Event\SellerRegistered;
use SellerPortal\Domain\Seller\Event\SellerRejected;
use SellerPortal\Domain\Seller\Event\SellerUnblocked;
use SellerPortal\Domain\Seller\InvalidSellerTransition;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Seller\SellerStatus;
use SellerPortal\Domain\Shared\UserId;
use Tests\Unit\SellerPortal\InMemory\PortalWorld;

function sellerHandlersRegister(PortalWorld $w, int $userId = 7): SellerId
{
    return (new RegisterSellerHandler($w->transactions, $w->clock, $w->dispatcher, $w->sellers))
        ->handle(new RegisterSeller($userId, 'Sklep Testowy', 'Poznań'));
}

test('rejestracja zapisuje sprzedawcę jako Pending i dispatchuje SellerRegistered', function () {
    $w = new PortalWorld;

    $id = sellerHandlersRegister($w);

    expect($w->sellers->get($id)->status())->toBe(SellerStatus::Pending)
        ->and($w->sellers->get($id)->version())->toBe(1)
        ->and($w->dispatcher->classes())->toBe([SellerRegistered::class])
        ->and($w->transactions->runs)->toBe(1);
});

test('rejestracja drugi raz dla tego samego usera rzuca SellerAlreadyRegistered', function () {
    $w = new PortalWorld;
    sellerHandlersRegister($w);

    expect(fn () => sellerHandlersRegister($w))->toThrow(SellerAlreadyRegistered::class)
        ->and($w->sellers->findByUserId(UserId::fromInt(7)))->not->toBeNull();
});

test('zatwierdzenie, blokada i odblokowanie zmieniają status i dispatchują zdarzenia', function () {
    $w = new PortalWorld;
    $id = sellerHandlersRegister($w);

    (new ApproveSellerHandler($w->transactions, $w->clock, $w->dispatcher, $w->sellers))->handle(new ApproveSeller($id->value(), 9));
    expect($w->sellers->get($id)->status())->toBe(SellerStatus::Active);

    (new BlockSellerHandler($w->transactions, $w->clock, $w->dispatcher, $w->sellers))->handle(new BlockSeller($id->value(), 9, 'Oszustwo'));
    expect($w->sellers->get($id)->status())->toBe(SellerStatus::Blocked);

    (new UnblockSellerHandler($w->transactions, $w->clock, $w->dispatcher, $w->sellers))->handle(new UnblockSeller($id->value(), 9));

    expect($w->sellers->get($id)->status())->toBe(SellerStatus::Active)
        ->and($w->dispatcher->classes())->toBe([SellerRegistered::class, SellerApproved::class, SellerBlocked::class, SellerUnblocked::class]);
});

test('odrzucenie zapisuje powód i dispatchuje SellerRejected', function () {
    $w = new PortalWorld;
    $id = sellerHandlersRegister($w);

    (new RejectSellerHandler($w->transactions, $w->clock, $w->dispatcher, $w->sellers))->handle(new RejectSeller($id->value(), 9, 'Brak danych'));

    expect($w->sellers->get($id)->statusReason()->value())->toBe('Brak danych')
        ->and($w->dispatcher->classes())->toContain(SellerRejected::class);
});

test('niedozwolone przejście nie zapisuje ani nie dispatchuje', function () {
    $w = new PortalWorld;
    $id = sellerHandlersRegister($w);
    $w->forgetDispatched();

    expect(fn () => (new UnblockSellerHandler($w->transactions, $w->clock, $w->dispatcher, $w->sellers))->handle(new UnblockSeller($id->value(), 9)))
        ->toThrow(InvalidSellerTransition::class)
        ->and($w->sellers->get($id)->version())->toBe(1)
        ->and($w->dispatcher->dispatched)->toBe([]);
});
