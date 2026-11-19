<?php

use Illuminate\Support\Facades\DB;
use SellerPortal\Domain\Offer\OfferRepository;

test('churn zapisuje zdarzenia ofert i product.deleted przez outbox', function () {
    $this->artisan('marketplace:seed', ['--n' => 40, '--no-publish' => true])->assertSuccessful();
    $productsBefore = DB::table('products')->count();
    DB::table('outbox')->truncate();

    $this->artisan('marketplace:churn', ['--rate' => 50, '--duration' => 1, '--delete-ratio' => 0.5])
        ->expectsOutputToContain('since=')
        ->assertSuccessful();

    $deletedIds = DB::table('outbox')->where('event_type', 'product.deleted')->pluck('aggregate_id');

    expect(DB::table('outbox')->where('aggregate_type', 'offer')->count())->toBeGreaterThan(0)
        ->and($deletedIds)->not->toBeEmpty()
        ->and(DB::table('products')->whereIn('id', $deletedIds)->count())->toBe(0)
        ->and(DB::table('products')->count())->toBe($productsBefore - $deletedIds->count());
});

test('churn podsumowuje liczby i ISO 8601 w linii since=', function () {
    $this->artisan('marketplace:seed', ['--n' => 10, '--no-publish' => true])->assertSuccessful();

    $this->artisan('marketplace:churn', ['--rate' => 20, '--duration' => 1, '--delete-ratio' => 0])
        ->expectsOutputToContain('deleted=0')
        ->assertSuccessful();

    expect(DB::table('outbox')->where('event_type', 'product.deleted')->count())->toBe(0);
});

test('churn liczy błędy w failed= i kończy się błędem, gdy nie udała się żadna próba', function () {
    $this->artisan('marketplace:seed', ['--n' => 5, '--no-publish' => true])->assertSuccessful();
    $this->mock(OfferRepository::class, function ($mock) {
        $mock->shouldReceive('get')->andThrow(new RuntimeException('baza leży'));
    });

    $this->artisan('marketplace:churn', ['--rate' => 20, '--duration' => 1, '--delete-ratio' => 0])
        ->expectsOutputToContain('baza leży')
        ->expectsOutputToContain('updated=0 deleted=0 failed=')
        ->assertFailed();

    expect(DB::table('outbox')->where('aggregate_type', 'offer')->count())->toBe(0);
});

test('churn bez błędów wypisuje failed=0', function () {
    $this->artisan('marketplace:seed', ['--n' => 10, '--no-publish' => true])->assertSuccessful();

    $this->artisan('marketplace:churn', ['--rate' => 20, '--duration' => 1, '--delete-ratio' => 0])
        ->expectsOutputToContain('failed=0')
        ->doesntExpectOutputToContain('Uwaga:')
        ->assertSuccessful();
});
