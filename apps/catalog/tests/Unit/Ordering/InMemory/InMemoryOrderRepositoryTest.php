<?php

use Ordering\Domain\OrderId;
use Ordering\Domain\OrderNotFound;
use SharedKernel\ConcurrencyConflict;
use Tests\Unit\Ordering\Domain\OrderMother;
use Tests\Unit\Ordering\InMemory\InMemoryOrderRepository;

test('wersje i kopie jak w repozytorium SQL', function () {
    $repo = new InMemoryOrderRepository;
    $order = OrderMother::placed();

    $repo->save($order);
    $first = $repo->get($order->id());
    $second = $repo->get($order->id());

    expect($first)->not->toBe($second)
        ->and($first->version())->toBe(1);

    $first->cancelByBuyer(OrderMother::now());
    $repo->save($first);
    $second->cancelByBuyer(OrderMother::now());

    expect(fn () => $repo->save($second))->toThrow(ConcurrencyConflict::class)
        ->and(fn () => $repo->get(OrderId::fromInt(99)))->toThrow(OrderNotFound::class);
});

test('failNextSaves rzuca zadaną liczbę razy', function () {
    $repo = new InMemoryOrderRepository;
    $order = OrderMother::placed();
    $repo->failNextSaves(2);

    expect(fn () => $repo->save($order))->toThrow(ConcurrencyConflict::class)
        ->and(fn () => $repo->save($order))->toThrow(ConcurrencyConflict::class);

    $repo->save($order);
    expect($repo->get($order->id())->version())->toBe(1);
});

test('idsDueForExpiry uwzględnia karencję i stronicowanie', function () {
    $repo = new InMemoryOrderRepository;
    $repo->save(OrderMother::placed());

    expect($repo->idsDueForExpiry(OrderMother::now()->modify('+19 minutes'), 0, 10))->toBe([])
        ->and($repo->idsDueForExpiry(OrderMother::now()->modify('+20 minutes'), 0, 10))->toHaveCount(1)
        ->and($repo->idsDueForExpiry(OrderMother::now()->modify('+20 minutes'), 7, 10))->toBe([]);
});
