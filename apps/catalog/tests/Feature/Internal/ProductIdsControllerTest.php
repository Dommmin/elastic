<?php

use App\Models\Product;

/**
 * Endpointy dla bramki jakości reindeksu i ścieżki "odbuduj z Postgresa"
 * (ETAP 10): liczba produktów oraz keysetowa lista ID. "Produkt" = każdy
 * wiersz `products` (projekcja istnieje dla każdego istniejącego produktu).
 */
test('count zwraca liczbę wszystkich produktów', function () {
    Product::factory()->count(5)->create();

    $this->getJson('/api/internal/products/count')
        ->assertOk()
        ->assertExactJson(['count' => 5]);
});

test('ids stronicuje keysetem bez dubli i luk', function () {
    $expected = Product::factory()->count(5)->create()->pluck('id')->sort()->values()->all();

    $seen = [];
    $after = 0;
    $pages = 0;

    do {
        $json = $this->getJson("/api/internal/products/ids?after={$after}&limit=2")->assertOk()->json();
        $seen = [...$seen, ...$json['ids']];
        $after = $json['next_after'];
        $pages++;
    } while ($after !== null);

    expect($seen)->toBe($expected)->and($pages)->toBe(3);
});

test('ostatnia pełna strona ma next_after null, gdy nie ma kolejnych', function () {
    Product::factory()->count(2)->create();

    $this->getJson('/api/internal/products/ids?limit=2')
        ->assertOk()
        ->assertJsonPath('next_after', null);
});

test('ids domyślnie zaczyna od początku', function () {
    $product = Product::factory()->create();

    $this->getJson('/api/internal/products/ids')
        ->assertOk()
        ->assertExactJson(['ids' => [$product->id], 'next_after' => null]);
});

test('limit poza zakresem 1..5000 daje 422', function (mixed $limit) {
    $this->getJson('/api/internal/products/ids?limit='.$limit)->assertStatus(422);
})->with([0, 5001, -1, 'abc']);

test('after ujemne daje 422', function () {
    $this->getJson('/api/internal/products/ids?after=-1')->assertStatus(422);
});
