<?php

use App\Models\Offer as OfferRow;
use App\Models\Product;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('strona produktu pokazuje tylko widoczne oferty z dostępną ilością', function () {
    $product = Product::factory()->create(['name' => 'Rower górski']);
    $visible = OfferRow::factory()->create(['product_id' => $product->id, 'price_cents' => 150000, 'stock' => 25]);
    OfferRow::factory()->draft()->create(['product_id' => $product->id]);
    OfferRow::factory()->withdrawn()->create(['product_id' => $product->id]);
    OfferRow::factory()->soldOut()->create(['product_id' => $product->id]);
    OfferRow::factory()->create(['product_id' => Product::factory()->create()->id]);

    $this->get(route('products.show', $product))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('shop/Product')
            ->where('product.name', 'Rower górski')
            ->has('offers', 1)
            ->where('offers.0.id', $visible->id)
            ->where('offers.0.price_cents', 150000)
            ->where('offers.0.available', 25)
            ->where('offers.0.max_quantity', 10));
});

test('maksymalna ilość to mniejsza z wartości: 10 i dostępne', function () {
    $product = Product::factory()->create();
    OfferRow::factory()->create(['product_id' => $product->id, 'stock' => 3]);

    $this->get(route('products.show', $product))
        ->assertInertia(fn (Assert $page) => $page->where('offers.0.available', 3)->where('offers.0.max_quantity', 3));
});

test('nieistniejący produkt to 404', function () {
    $this->get('/products/999999')->assertNotFound();
});

test('strona produktu jest publiczna, a własna oferta jest oznaczona', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    OfferRow::factory()->create(['product_id' => $product->id, 'seller_id' => SellerRow::factory()->forUser($user)->create()->id]);

    $this->get(route('products.show', $product))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('offers.0.is_own', false));

    $this->actingAs($user)->get(route('products.show', $product))
        ->assertInertia(fn (Assert $page) => $page->where('offers.0.is_own', true));
});
