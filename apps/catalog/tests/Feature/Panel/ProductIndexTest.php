<?php

use App\Models\Category;
use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function subcategory(string $dzial = 'Dział', string $pod = 'Pod'): Category
{
    $root = Category::factory()->create(['name' => $dzial, 'path' => null]);
    $root->update(['path' => (string) $root->id]);
    $leaf = Category::factory()->create(['name' => $pod, 'parent_id' => $root->id, 'path' => $root->id.'.99']);
    $leaf->update(['path' => $root->id.'.'.$leaf->id]);

    return $leaf;
}

test('gość jest przekierowany na login', function () {
    $this->get('/panel/products')->assertRedirect('/login');
});

test('zalogowany nie-admin dostaje 403', function () {
    $this->actingAs(User::factory()->create())
        ->get('/panel/products')
        ->assertForbidden();
});

test('lista pokazuje produkty z etykietą kategorii i najniższą aktywną ceną', function () {
    $product = Product::factory()->create(['category_id' => subcategory()->id]);
    Offer::factory()->for($product)->create(['price_cents' => 500, 'active' => true]);
    Offer::factory()->for($product)->create(['price_cents' => 300, 'active' => true]);
    Offer::factory()->for($product)->create(['price_cents' => 100, 'active' => false]);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/panel/products')
        ->assertInertia(fn (Assert $page) => $page
            ->component('panel/Products')
            ->has('products.data', 1)
            ->where('products.data.0.id', $product->id)
            ->where('products.data.0.brand', $product->brand->name)
            ->where('products.data.0.category_label', 'Dział › Pod')
            ->where('products.data.0.active_offers_count', 2)
            ->where('products.data.0.min_price_cents', 300)
            ->where('filters.q', ''));
});

test('q liczbowe szuka po ID, tekst po nazwie bez wielkości liter', function () {
    $cat = subcategory()->id;
    $a = Product::factory()->create(['name' => 'Buty Biegowe', 'category_id' => $cat]);
    $b = Product::factory()->create(['name' => 'Kurtka', 'category_id' => $cat]);
    $user = User::factory()->admin()->create();

    $this->actingAs($user)->get('/panel/products?q='.$b->id)
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.id', $b->id));

    $this->actingAs($user)->get('/panel/products?q=biegowe')
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.id', $a->id));
});

test('25 na stronę', function () {
    Product::factory()->count(30)->create(['category_id' => subcategory()->id]);

    $this->actingAs(User::factory()->admin()->create())->get('/panel/products')
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 25));
});
