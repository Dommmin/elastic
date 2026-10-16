<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Seller\RegisterSeller;
use SellerPortal\Application\Seller\RegisterSellerHandler;
use SellerPortal\Domain\Seller\Event\SellerRegistered;
use SellerPortal\Domain\Seller\Event\SellerUnblocked;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\UserId;
use SellerPortal\Infrastructure\InProcessDomainEventDispatcher;
use SharedKernel\DomainEvent;

test('kontener składa handler z implementacjami SQL, a dispatcher jest scoped', function () {
    $user = User::factory()->create();

    $id = app(RegisterSellerHandler::class)->handle(new RegisterSeller($user->id, 'Sklep Śródmieście', 'Poznań'));

    expect(DB::table('sellers')->where('id', $id->value())->value('user_id'))->toBe($user->id)
        ->and(app(DomainEventDispatcher::class))->toBe(app(InProcessDomainEventDispatcher::class))
        ->and(app(DomainEventDispatcher::class))->toBe(app(DomainEventDispatcher::class));
});

test('dispatcher woła słuchaczy w kolejności rejestracji i ignoruje zdarzenia bez słuchaczy', function () {
    $dispatcher = new InProcessDomainEventDispatcher;
    $calls = [];
    $dispatcher->listen(SellerRegistered::class, function (DomainEvent $e) use (&$calls) {
        $calls[] = 'pierwszy';
    });
    $dispatcher->listen(SellerRegistered::class, function (DomainEvent $e) use (&$calls) {
        $calls[] = 'drugi';
    });

    $registered = new SellerRegistered(
        SellerId::fromInt(1),
        UserId::fromInt(1),
        new DateTimeImmutable('2026-10-15 12:00:00'),
    );
    $unrelated = new SellerUnblocked(
        SellerId::fromInt(1),
        ModeratorId::fromInt(1),
        new DateTimeImmutable('2026-10-15 12:00:00'),
    );

    $dispatcher->dispatch([$unrelated, $registered]);

    expect($calls)->toBe(['pierwszy', 'drugi']);
});
