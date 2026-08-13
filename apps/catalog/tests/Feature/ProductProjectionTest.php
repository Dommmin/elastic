<?php

use App\Models\Offer;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;

/**
 * search-service (Symfony) buduje dokument Elasticsearcha WYŁĄCZNIE na
 * podstawie tego JSON-a (docs/02-APLIKACJE.md, sekcja 7.1, wariant B).
 * Ten test to w praktyce kontrakt: zmiana kształtu odpowiedzi bez update'u
 * mapowania ES po drugiej stronie cicho zepsuje indeksację.
 */
test('projekcja produktu zawiera dane potrzebne do zbudowania dokumentu ES', function () {
    $product = Product::factory()->create([
        'name' => 'Laptop testowy',
        'attributes' => ['ram' => '16GB'],
    ]);

    $activeOffer = Offer::factory()->create([
        'product_id' => $product->id,
        'price_cents' => 249900,
        'stock' => 3,
        'active' => true,
    ]);

    // Nieaktywna oferta NIE powinna wpływać na price_min/price_max/in_stock —
    // to jest dokładnie ten filtr, który realnie widzi użytkownik wyszukiwarki.
    Offer::factory()->create([
        'product_id' => $product->id,
        'price_cents' => 1,
        'stock' => 999,
        'active' => false,
    ]);

    $user = User::factory()->create();
    Review::createWithOutbox([
        'product_id' => $product->id,
        'user_id' => $user->id,
        'rating' => 4,
        'body' => 'OK',
    ], 'review.created');

    $response = $this->getJson("/api/internal/products/{$product->id}/projection");

    $response->assertOk()
        ->assertJsonPath('name', 'Laptop testowy')
        ->assertJsonPath('attributes.ram', '16GB')
        ->assertJsonPath('price_min', 249900)
        ->assertJsonPath('price_max', 249900)
        ->assertJsonPath('in_stock', true)
        ->assertJsonPath('rating_avg', 4.0)
        ->assertJsonPath('rating_count', 1)
        ->assertJsonCount(1, 'offers')
        ->assertJsonPath('offers.0.price', 249900);
});

test('produkt bez aktywnych ofert ma in_stock=false i price_min=null', function () {
    $product = Product::factory()->create();

    Offer::factory()->create(['product_id' => $product->id, 'active' => false]);

    $response = $this->getJson("/api/internal/products/{$product->id}/projection");

    $response->assertOk()
        ->assertJsonPath('in_stock', false)
        ->assertJsonPath('price_min', null)
        ->assertJsonCount(0, 'offers');
});

test('nieistniejący produkt zwraca 404', function () {
    $this->getJson('/api/internal/products/999999/projection')->assertNotFound();
});
