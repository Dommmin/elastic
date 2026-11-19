<?php

use Illuminate\Support\Facades\DB;

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
