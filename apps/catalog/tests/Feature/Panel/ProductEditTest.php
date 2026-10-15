<?php

use App\Models\Offer;
use App\Models\Product;
use App\Models\User;

test('strona edycji produktu zwraca oferty posortowane po id', function () {
    $product = Product::factory()->create();
    $second = Offer::factory()->create(['product_id' => $product->id, 'price_cents' => 1000, 'stock' => 3]);
    $first = Offer::factory()->create(['product_id' => $product->id, 'price_cents' => 1000, 'stock' => 3]);

    $this->actingAs(User::factory()->admin()->create())
        ->get("/panel/products/{$product->id}")
        ->assertInertia(fn ($page) => $page
            ->has('offers', 2)
            ->where('offers.0.id', $second->id)
            ->where('offers.1.id', $first->id)
            ->has('offers.0', fn ($o) => $o->hasAll(['id', 'seller', 'seller_status', 'price_cents', 'pending_price_cents', 'stock', 'status', 'suspension', 'allowed_actions', 'version'])));
});
