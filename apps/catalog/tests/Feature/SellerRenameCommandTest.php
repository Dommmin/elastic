<?php

use App\Models\Seller;
use Illuminate\Support\Facades\DB;

test('zmienia nazwę sprzedawcy w bazie i nie dopisuje nic do outboxu', function () {
    $this->artisan('marketplace:seed', ['--n' => 5, '--no-publish' => true])->assertSuccessful();
    $seller = Seller::query()->firstOrFail();
    DB::table('outbox')->truncate();

    $this->artisan('marketplace:seller-rename', ['seller_id' => $seller->id, 'name' => 'Nowa Nazwa'])
        ->expectsOutputToContain('Nowa Nazwa')
        ->assertSuccessful();

    expect($seller->fresh()->name)->toBe('Nowa Nazwa')
        ->and(DB::table('outbox')->count())->toBe(0);
});

test('odmawia dla nieistniejącego sprzedawcy i pustej nazwy', function () {
    $this->artisan('marketplace:seller-rename', ['seller_id' => 999999, 'name' => 'X'])->assertFailed();

    $seller = Seller::factory()->create(['name' => 'Stara']);
    $this->artisan('marketplace:seller-rename', ['seller_id' => $seller->id, 'name' => '  '])->assertFailed();

    expect($seller->fresh()->name)->toBe('Stara');
});
