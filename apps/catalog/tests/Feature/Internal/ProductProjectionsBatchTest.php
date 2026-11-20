<?php

use App\Models\Offer;
use App\Models\Product;
use App\Models\Seller;
use App\Services\Search\ProductProjectionBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Wsadowe projekcje (ETAP 10, Task 12b): droga B reindeksu i nadrabianie po
 * reindex-abort pobierają do 500 projekcji jednym żądaniem zamiast jednego
 * żądania na produkt (~37 dok./s w Task 12). Kształt pozycji = dokładnie
 * kształt `ProductProjectionBuilder`, jeden `projected_at_us` dla całej partii.
 */
function productWithOffers(int $offers = 2): Product
{
    $product = Product::factory()->create();
    Offer::factory()->count($offers)->create(['product_id' => $product->id, 'seller_id' => Seller::factory()]);

    return $product;
}

test('istniejące produkty w items, nieistniejące w missing, jeden projected_at_us', function () {
    $products = collect(range(1, 3))->map(fn () => productWithOffers());
    $ghost = $products->max('id') + 1000;
    $ids = [...$products->pluck('id')->all(), $ghost];

    $json = $this->getJson('/api/internal/products/projections?ids='.implode(',', $ids))->assertOk()->json();

    expect($json['projected_at_us'])->toBeInt()
        ->and(array_column($json['items'], 'id'))->toEqualCanonicalizing($products->pluck('id')->all())
        ->and($json['missing'])->toBe([$ghost])
        ->and(array_unique(array_column($json['items'], 'projected_at_us')))->toBe([$json['projected_at_us']]);
});

test('pozycja ma dokładnie kształt projekcji z buildera', function () {
    $product = productWithOffers();

    $item = $this->getJson("/api/internal/products/projections?ids={$product->id}")->assertOk()->json('items.0');
    $single = json_decode(json_encode(app(ProductProjectionBuilder::class)->build($product->id, $item['projected_at_us']), JSON_PRESERVE_ZERO_FRACTION), true);

    unset($item['indexed_at'], $single['indexed_at']);
    expect($item)->toBe($single);
});

test('liczba zapytań SQL nie zależy od liczby ID w partii', function () {
    $few = collect(range(1, 2))->map(fn () => productWithOffers())->pluck('id');
    $many = collect(range(1, 20))->map(fn () => productWithOffers())->pluck('id');

    $count = function ($ids) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/internal/products/projections?ids='.$ids->implode(','))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count($many))->toBe($count($few));
});

test('ponad 500 ID daje 422', function () {
    $this->getJson('/api/internal/products/projections?ids='.implode(',', range(1, 501)))->assertStatus(422);
});

test('500 ID jest dozwolone', function () {
    $this->getJson('/api/internal/products/projections?ids='.implode(',', range(1, 500)))
        ->assertOk()
        ->assertJsonCount(500, 'missing');
});

test('zły format ids daje 422', function (string $query) {
    $this->getJson('/api/internal/products/projections'.$query)->assertStatus(422);
})->with([
    'brak ids' => [''],
    'puste' => ['?ids='],
    'litery' => ['?ids=1,a,3'],
    'ujemne' => ['?ids=-1'],
    'podwójny przecinek' => ['?ids=1,,2'],
]);
