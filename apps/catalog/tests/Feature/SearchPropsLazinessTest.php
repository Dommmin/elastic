<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Services\ProductSearchService;
use App\Services\Search\SearchResult;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;

/**
 * DoD ETAP 7 (docs/06-PLAN-WDROZENIA.md): "kliknięcie facetu NIE wywołuje
 * niepotrzebnych agregacji w ES". Ten plik to udowadnia BEZ żywego ES —
 * `ProductSearchService` jest mockiem, a Mockery liczy, ile razy kontroler
 * faktycznie zawołał `search()` i `priceHistogram()` w danym żądaniu.
 *
 * Każde wywołanie metody serwisu = jedno zapytanie do ES z agregacjami, więc
 * licznik wywołań jest wprost licznikiem zapytań, które zobaczyłbyś
 * w slowlogu (`make es-slowlog-on`).
 */
function fakeSearchResult(): SearchResult
{
    return new SearchResult(
        items: [['id' => '1', 'name' => 'Laptop testowy']],
        facets: ['brand' => [], 'category' => [], 'price' => [], 'in_stock' => []],
        total: ['value' => 1, 'isLowerBound' => false],
        nextCursor: null,
        tookMs: 1,
    );
}

test('pierwsze wejście liczy wyszukiwanie RAZ (wyniki i facety z jednego zapytania), histogram odkłada', function () {
    $this->mock(ProductSearchService::class, function (MockInterface $mock) {
        $mock->shouldReceive('search')->once()->andReturn(fakeSearchResult());
        $mock->shouldNotReceive('priceHistogram');
    });

    $this->get('/search?q=laptop')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Search')
            ->has('results.data', 1)
            ->has('facets')
            ->missing('priceHistogram')
        );
});

test('automatyczny request po histogram (deferred) NIE przelicza wyszukiwania', function () {
    $this->mock(ProductSearchService::class, function (MockInterface $mock) {
        // once() — to wywołanie z PIERWSZEGO żądania; reload nie może dodać drugiego.
        $mock->shouldReceive('search')->once()->andReturn(fakeSearchResult());
        $mock->shouldReceive('priceHistogram')->once()->andReturn([['price' => 0, 'count' => 1]]);
    });

    $this->get('/search?q=laptop')
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('priceHistogram', 1)
                ->missing('results')
                ->missing('facets')
            )
        );
});

test('klik w facet (partial reload results+facets) NIE odpala histogramu cen', function () {
    $this->mock(ProductSearchService::class, function (MockInterface $mock) {
        // Dwa razy: pierwsze wejście + partial reload po kliknięciu facetu.
        $mock->shouldReceive('search')->twice()->andReturn(fakeSearchResult());
        $mock->shouldNotReceive('priceHistogram');
    });

    $this->get('/search?q=laptop&brand[]=Acme')
        ->assertInertia(fn (Assert $page) => $page
            ->reloadOnly(['results', 'facets', 'filters'], fn (Assert $reload) => $reload
                ->where('filters.brand', ['Acme'])
                ->missing('priceHistogram')
            )
        );
});

test('results to scroll prop: kursorowe metadane dla <InfiniteScroll> i doklejanie po id', function () {
    $this->mock(ProductSearchService::class, function (MockInterface $mock) {
        $mock->shouldReceive('search')->once()->andReturn(new SearchResult(
            items: [['id' => '1']],
            facets: [],
            total: ['value' => 50, 'isLowerBound' => false],
            nextCursor: 'kursor-strony-2',
            tookMs: 1,
        ));
    });

    // Surowy JSON strony Inertii (nagłówek X-Inertia) — AssertableInertia nie
    // wystawia scrollProps/mergeProps, a to jest właśnie kontrakt z frontem.
    $page = $this->get('/search?q=laptop', ['X-Inertia' => 'true', 'X-Inertia-Version' => inertiaAssetVersion()])
        ->assertOk()
        ->json();

    expect($page['scrollProps']['results'])->toMatchArray([
        'pageName' => 'cursor',
        'nextPage' => 'kursor-strony-2',
        'previousPage' => null,
    ])
        ->and($page['mergeProps'])->toContain('results.data')
        ->and($page['matchPropsOn'])->toContain('results.data.id');
});

test('zmiana filtrów z resetem results NIE dokleja do starej listy (brak mergeProps)', function () {
    $this->mock(ProductSearchService::class, function (MockInterface $mock) {
        $mock->shouldReceive('search')->once()->andReturn(fakeSearchResult());
    });

    $page = $this->get('/search?q=laptop&brand[]=Acme', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => inertiaAssetVersion(),
        'X-Inertia-Partial-Component' => 'Search',
        'X-Inertia-Partial-Data' => 'results,facets,filters',
        'X-Inertia-Reset' => 'results',
    ])->assertOk()->json();

    expect($page['mergeProps'] ?? [])->not->toContain('results.data')
        ->and($page['scrollProps']['results']['reset'])->toBeTrue();
});

function inertiaAssetVersion(): string
{
    return (string) app(HandleInertiaRequests::class)->version(request());
}
