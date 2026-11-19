<?php

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Średnie ocen wyliczone tak jak w projekcji (`withAvg('reviews', 'rating')`,
 * zaokrąglone do 2 miejsc), klucz = id produktu.
 *
 * @return array<int, float>
 */
function reviewAverages(): array
{
    return Product::query()->has('reviews')->withAvg('reviews', 'rating')->get()
        ->mapWithKeys(fn (Product $p) => [$p->id => round((float) $p->reviews_avg_rating, 2)])
        ->all();
}

test('--products=20 daje recenzje dokładnie 20 produktom, średnie w zakresie 1–5', function () {
    $this->artisan('marketplace:seed', ['--n' => 60, '--no-publish' => true])->assertSuccessful();

    $this->artisan('marketplace:seed-reviews', ['--products' => 20])
        ->expectsOutputToContain('products=20 ')
        ->assertSuccessful();

    $averages = reviewAverages();

    expect($averages)->toHaveCount(20)
        ->and(min($averages))->toBeGreaterThanOrEqual(1.0)
        ->and(max($averages))->toBeLessThanOrEqual(5.0);
});

test('część produktów ma średnią dokładnie 4.70, część 3.30', function () {
    $this->artisan('marketplace:seed', ['--n' => 60, '--no-publish' => true])->assertSuccessful();

    $this->artisan('marketplace:seed-reviews', ['--products' => 20])->assertSuccessful();

    $averages = array_map(fn (float $v) => number_format($v, 2), reviewAverages());

    expect(array_count_values($averages))->toHaveKeys(['4.70', '3.30'])
        ->and(DB::table('reviews')->count())->toBeGreaterThanOrEqual(20);
});

test('linia podsumowania ma postać products=… reviews=… avg_4_70=… avg_3_30=…', function () {
    $this->artisan('marketplace:seed', ['--n' => 30, '--no-publish' => true])->assertSuccessful();

    $this->artisan('marketplace:seed-reviews', ['--products' => 10])
        ->expectsOutputToContain('avg_4_70=2 avg_3_30=2')
        ->assertSuccessful();
});

test('dla każdego produktu z recenzjami jest nowe zdarzenie review.created z jego product_id', function () {
    $this->artisan('marketplace:seed', ['--n' => 40, '--no-publish' => true])->assertSuccessful();
    DB::table('outbox')->truncate();

    $this->artisan('marketplace:seed-reviews', ['--products' => 20])->assertSuccessful();

    $events = DB::table('outbox')->where('event_type', 'review.created')->get();
    $productIds = $events->map(fn ($e) => json_decode($e->payload, true)['product_id'])->unique();

    expect($events)->toHaveCount(20)
        ->and($productIds)->toHaveCount(20)
        ->and($productIds->sort()->values()->all())
        ->toEqual(DB::table('reviews')->distinct()->pluck('product_id')->sort()->values()->all());
});

test('drugie uruchomienie nie dodaje recenzji produktom, które już je mają', function () {
    $this->artisan('marketplace:seed', ['--n' => 30, '--no-publish' => true])->assertSuccessful();

    $this->artisan('marketplace:seed-reviews', ['--products' => 30])->assertSuccessful();
    $reviews = DB::table('reviews')->count();
    $events = DB::table('outbox')->where('event_type', 'review.created')->count();

    $this->artisan('marketplace:seed-reviews', ['--products' => 30])
        ->expectsOutputToContain('products=0 ')
        ->assertSuccessful();

    expect(DB::table('reviews')->count())->toBe($reviews)
        ->and(DB::table('outbox')->where('event_type', 'review.created')->count())->toBe($events);
});

test('ten sam --seed wybiera te same produkty', function () {
    $this->artisan('marketplace:seed', ['--n' => 60, '--no-publish' => true])->assertSuccessful();

    $this->artisan('marketplace:seed-reviews', ['--products' => 15, '--seed' => 7])->assertSuccessful();
    $first = DB::table('reviews')->distinct()->pluck('product_id')->sort()->values()->all();

    DB::table('reviews')->delete();
    $this->artisan('marketplace:seed-reviews', ['--products' => 15, '--seed' => 7])->assertSuccessful();

    expect(DB::table('reviews')->distinct()->pluck('product_id')->sort()->values()->all())->toEqual($first);
});
