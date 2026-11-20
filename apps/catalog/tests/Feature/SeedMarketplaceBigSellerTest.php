<?php

use App\Models\Seller;
use Illuminate\Support\Facades\DB;

test('duży sprzedawca ma ofertę w dokładnie M produktach', function () {
    $this->artisan('marketplace:seed', ['--n' => 50, '--big-seller' => 20, '--no-publish' => true])->assertSuccessful();

    $big = Seller::query()->where('name', 'Megasklep')->firstOrFail();

    expect(DB::table('offers')->where('seller_id', $big->id)->distinct()->count('product_id'))->toBe(20)
        ->and(DB::table('offers')->where('seller_id', $big->id)->count())->toBe(20)
        ->and(DB::table('products')->count())->toBe(50)
        ->and(DB::table('products')->whereNotIn('id', DB::table('offers')->select('product_id'))->count())->toBe(0);
});

test('bez --big-seller nie powstaje sprzedawca Megasklep', function () {
    $this->artisan('marketplace:seed', ['--n' => 5, '--no-publish' => true])->assertSuccessful();

    expect(Seller::query()->where('name', 'Megasklep')->exists())->toBeFalse();
});

test('duży sprzedawca większy niż n kończy się błędem', function () {
    $this->artisan('marketplace:seed', ['--n' => 10, '--big-seller' => 11, '--no-publish' => true])
        ->expectsOutputToContain('--big-seller')
        ->assertFailed();

    expect(DB::table('products')->count())->toBe(0);
});
