<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Outbox;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function leafCategory(string $dzial = 'Dział', string $pod = 'Pod'): Category
{
    $root = Category::factory()->create(['name' => $dzial, 'path' => null]);
    $root->update(['path' => (string) $root->id]);
    $leaf = Category::factory()->create(['name' => $pod, 'parent_id' => $root->id, 'path' => $root->id.'.99']);
    $leaf->update(['path' => $root->id.'.'.$leaf->id]);

    return $leaf;
}

test('gość i nie-admin nie edytują produktu', function () {
    $product = Product::factory()->create(['category_id' => leafCategory()->id]);

    $this->get("/panel/products/{$product->id}")->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->put("/panel/products/{$product->id}", [])->assertForbidden();
});

test('zmiana nazwy tworzy jedno zdarzenie product.updated i podbija wersję', function () {
    $product = Product::factory()->create(['name' => 'Stara', 'category_id' => leafCategory()->id]);
    $version = $product->version;

    $this->actingAs(User::factory()->admin()->create())
        ->put("/panel/products/{$product->id}", [
            'name' => 'Nowa',
            'brand_id' => $product->brand_id,
            'category_id' => $product->category_id,
        ])
        ->assertRedirect();

    expect(Outbox::where('event_type', 'product.updated')->count())->toBe(1)
        ->and($product->fresh()->version)->toBe($version + 1)
        ->and($product->fresh()->name)->toBe('Nowa');
});

test('po zapisie ze zmianą flash mówi, że zdarzenie jest w drodze', function () {
    $product = Product::factory()->create(['name' => 'Stara', 'category_id' => leafCategory()->id]);

    $this->actingAs(User::factory()->admin()->create())
        ->followingRedirects()
        ->from("/panel/products/{$product->id}")
        ->put("/panel/products/{$product->id}", [
            'name' => 'Nowa',
            'brand_id' => $product->brand_id,
            'category_id' => $product->category_id,
        ])
        ->assertInertia(fn (Assert $page) => $page->hasFlash('status', 'Zapisano, zdarzenie w drodze'));
});

test('zapis bez zmian nie tworzy zdarzenia', function () {
    $product = Product::factory()->create(['category_id' => leafCategory()->id]);
    $version = $product->version;

    $this->actingAs(User::factory()->admin()->create())
        ->followingRedirects()
        ->from("/panel/products/{$product->id}")
        ->put("/panel/products/{$product->id}", [
            'name' => $product->name,
            'brand_id' => (string) $product->brand_id,
            'category_id' => (string) $product->category_id,
        ])
        ->assertInertia(fn (Assert $page) => $page->hasFlash('status', 'Bez zmian'));

    expect(Outbox::where('event_type', 'product.updated')->count())->toBe(0)
        ->and($product->fresh()->version)->toBe($version);
});

test('kategoria-dział jest odrzucana', function () {
    $product = Product::factory()->create(['category_id' => leafCategory()->id]);
    $root = Category::factory()->create(['parent_id' => null]);

    $this->actingAs(User::factory()->admin()->create())
        ->put("/panel/products/{$product->id}", [
            'name' => 'X',
            'brand_id' => $product->brand_id,
            'category_id' => $root->id,
        ])
        ->assertSessionHasErrors('category_id');

    expect(Outbox::count())->toBe(0);
});

test('nieistniejąca marka jest odrzucana', function () {
    $product = Product::factory()->create(['category_id' => leafCategory()->id]);

    $this->actingAs(User::factory()->admin()->create())
        ->put("/panel/products/{$product->id}", [
            'name' => 'X',
            'brand_id' => 999999,
            'category_id' => $product->category_id,
        ])
        ->assertSessionHasErrors('brand_id');
});

test('strona edycji ma tylko podkategorie z etykietami', function () {
    $leaf = leafCategory('Odzież', 'Buty');
    $product = Product::factory()->create(['category_id' => $leaf->id]);
    Brand::factory()->count(2)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get("/panel/products/{$product->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('panel/ProductEdit')
            ->where('product.id', $product->id)
            ->where('product.version', $product->version)
            ->has('categories', 1)
            ->where('categories.0.id', $leaf->id)
            ->where('categories.0.label', 'Odzież › Buty')
            ->has('brands', Brand::count()));
});
