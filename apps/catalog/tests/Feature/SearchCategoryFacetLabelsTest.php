<?php

use App\Models\Category;
use App\Services\ProductSearchService;
use App\Services\Search\SearchResult;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;

/**
 * ES zwraca w facecie `category` same ścieżki ID ("3.17") — kontroler
 * dokleja nazwy z Postgresa, żeby UI nie pokazywało liczb.
 */
function searchResultWithCategoryBuckets(array $buckets): SearchResult
{
    return new SearchResult(
        items: [],
        facets: ['brand' => [], 'category' => $buckets, 'price' => [], 'in_stock' => []],
        total: ['value' => 0, 'isLowerBound' => false],
        nextCursor: null,
        tookMs: 1,
    );
}

test('facet kategorii dostaje nazwy, podkategorie działu stoją obok siebie', function () {
    $sport = Category::factory()->create(['name' => 'Sport']);
    $running = Category::factory()->create(['name' => 'Bieganie', 'parent_id' => $sport->id]);
    $sportX = Category::factory()->create(['name' => 'Sport X']);

    $this->mock(ProductSearchService::class, function (MockInterface $mock) use ($sport, $running, $sportX) {
        $mock->shouldReceive('search')->andReturn(searchResultWithCategoryBuckets([
            ['key' => "{$sport->id}.{$running->id}", 'from' => null, 'to' => null, 'count' => 4],
            ['key' => (string) $sportX->id, 'from' => null, 'to' => null, 'count' => 9],
            ['key' => (string) $sport->id, 'from' => null, 'to' => null, 'count' => 7],
        ]));
    });

    $this->get('/search')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('facets.category.0.label', 'Sport')
            ->where('facets.category.1.label', 'Sport › Bieganie')
            ->where('facets.category.1.key', "{$sport->id}.{$running->id}")
            ->where('facets.category.2.label', 'Sport X')
        );
});

test('nieznana kategoria w indeksie zostaje pokazana jako ścieżka, nie wywala strony', function () {
    $this->mock(ProductSearchService::class, function (MockInterface $mock) {
        $mock->shouldReceive('search')->andReturn(searchResultWithCategoryBuckets([
            ['key' => '999.1000', 'from' => null, 'to' => null, 'count' => 1],
        ]));
    });

    $this->get('/search')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('facets.category.0.label', '999.1000')
        );
});
