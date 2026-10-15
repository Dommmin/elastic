<?php

use App\Models\Offer;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Database\QueryException;

test('listed() = published i stock > 0', function () {
    $product = Product::factory()->create();
    $visible = Offer::factory()->for($product)->create(['stock' => 5]);
    Offer::factory()->for($product)->create(['stock' => 0]);
    Offer::factory()->for($product)->draft()->create(['stock' => 5]);
    Offer::factory()->for($product)->create(['stock' => 5, 'status' => 'suspended']);
    Offer::factory()->for($product)->withdrawn()->create(['stock' => 5]);
    Offer::factory()->for($product)->soldOut()->create();

    expect(Offer::listed()->pluck('id')->all())->toBe([$visible->id])
        ->and($product->listedOffers()->pluck('id')->all())->toBe([$visible->id]);
});

test('projekcja pomija ofertę wyprzedaną i zawieszoną', function () {
    $product = Product::factory()->create();
    $visible = Offer::factory()->for($product)->create(['stock' => 4, 'price_cents' => 1500]);
    Offer::factory()->for($product)->soldOut()->create(['price_cents' => 100]);
    Offer::factory()->for($product)->create(['stock' => 9, 'status' => 'suspended', 'price_cents' => 50]);

    $this->getJson("/api/internal/products/{$product->id}/projection")
        ->assertOk()
        ->assertJsonCount(1, 'offers')
        ->assertJsonPath('offers.0.offer_id', (string) $visible->id)
        ->assertJsonPath('price_min', 1500);
});

test('produkt bez widocznych ofert: offers [], price_min null, in_stock false', function () {
    $product = Product::factory()->create();
    Offer::factory()->for($product)->draft()->create(['stock' => 5]);

    $this->getJson("/api/internal/products/{$product->id}/projection")
        ->assertOk()
        ->assertJsonPath('offers', [])
        ->assertJsonPath('price_min', null)
        ->assertJsonPath('price_max', null)
        ->assertJsonPath('in_stock', false);
});

test('indeks nie pozwala na drugą żywą ofertę sprzedawcy na produkt, wycofana nie blokuje', function () {
    $product = Product::factory()->create();
    $seller = Seller::factory()->create();

    Offer::factory()->for($product)->for($seller)->withdrawn()->create();
    Offer::factory()->for($product)->for($seller)->create();

    expect(fn () => Offer::factory()->for($product)->for($seller)->draft()->create())
        ->toThrow(QueryException::class);

    // Inny sprzedawca na ten sam produkt i wycofane duplikaty są dozwolone.
    Offer::factory()->for($product)->create();
    Offer::factory()->for($product)->for($seller)->withdrawn()->create();

    expect(Offer::where('seller_id', $seller->id)->count())->toBe(3);
});
