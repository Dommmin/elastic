<?php

use App\Models\User;
use App\Support\RaceTest\RaceFixture;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

test('fixture jest oznaczone i sprzątanie usuwa tylko jego wiersze', function () {
    $other = User::factory()->create();
    $fixture = RaceFixture::create('abc123', buyers: 3, stock: 2);

    expect(DB::table('users')->where('email', 'like', 'race-abc123-%@example.test')->count())->toBe(3)
        ->and(DB::table('offers')->where('id', $fixture->offerId)->value('stock'))->toBe(2);

    Artisan::call('orders:race-test:buyer', ['userId' => $fixture->userIds[0], 'offerId' => $fixture->offerId]);
    expect(DB::table('orders')->whereIn('buyer_user_id', $fixture->userIds)->count())->toBe(1);

    $fixture->cleanUp();

    expect(DB::table('users')->whereIn('id', $fixture->userIds)->count())->toBe(0)
        ->and(DB::table('orders')->whereIn('buyer_user_id', $fixture->userIds)->count())->toBe(0)
        ->and(DB::table('offers')->where('id', $fixture->offerId)->exists())->toBeFalse()
        ->and(DB::table('products')->where('id', $fixture->productId)->exists())->toBeFalse()
        ->and(DB::table('sellers')->where('id', $fixture->sellerId)->exists())->toBeFalse()
        ->and(DB::table('users')->where('id', $other->id)->exists())->toBeTrue();
});

test('sprzątanie zostawia w outboxie product.deleted, żeby indeks wyszukiwarki usunął produkt', function () {
    $fixture = RaceFixture::create('del456', buyers: 1, stock: 1);
    $fixture->cleanUp();

    $rows = DB::table('outbox')->where('aggregate_type', 'product')->where('aggregate_id', (string) $fixture->productId)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->event_type)->toBe('product.deleted')
        ->and((int) $rows[0]->sequence)->toBe(2)
        ->and(json_decode($rows[0]->payload, true)['id'])->toBe($fixture->productId)
        ->and(DB::table('outbox')->where('aggregate_type', 'offer')->where('aggregate_id', (string) $fixture->offerId)->count())->toBe(0);
});
