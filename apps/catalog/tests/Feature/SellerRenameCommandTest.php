<?php

use App\Models\Seller;
use Illuminate\Support\Facades\DB;

test('zmienia nazwę sprzedawcy w bazie i dopisuje do outboxu tylko seller.renamed (ETAP 13)', function () {
    $this->artisan('marketplace:seed', ['--n' => 5, '--no-publish' => true])->assertSuccessful();
    $seller = Seller::query()->firstOrFail();
    DB::table('outbox')->truncate();

    $this->artisan('marketplace:seller-rename', ['seller_id' => $seller->id, 'name' => 'Nowa Nazwa'])
        ->expectsOutputToContain('Nowa Nazwa')
        ->assertSuccessful();

    expect($seller->fresh()->name)->toBe('Nowa Nazwa')
        ->and(DB::table('outbox')->pluck('event_type')->all())->toBe(['seller.renamed']);
});

test('odmawia dla nieistniejącego sprzedawcy i pustej nazwy', function () {
    $this->artisan('marketplace:seller-rename', ['seller_id' => 999999, 'name' => 'X'])->assertFailed();

    $seller = Seller::factory()->create(['name' => 'Stara']);
    $this->artisan('marketplace:seller-rename', ['seller_id' => $seller->id, 'name' => '  '])->assertFailed();

    expect($seller->fresh()->name)->toBe('Stara');
});

test('zmiana nazwy idzie ścieżką domenową: wersja sprzedawcy rośnie o 1', function () {
    $seller = Seller::factory()->create(['name' => 'Stara', 'version' => 4]);

    $this->artisan('marketplace:seller-rename', ['seller_id' => $seller->id, 'name' => 'Nowa'])->assertSuccessful();

    expect($seller->fresh()->name)->toBe('Nowa')
        ->and($seller->fresh()->version)->toBe(5);
});

test('ta sama nazwa nie zmienia wersji', function () {
    $seller = Seller::factory()->create(['name' => 'Ta Sama', 'version' => 4]);

    $this->artisan('marketplace:seller-rename', ['seller_id' => $seller->id, 'name' => 'Ta Sama'])->assertSuccessful();

    expect($seller->fresh()->version)->toBe(4);
});
