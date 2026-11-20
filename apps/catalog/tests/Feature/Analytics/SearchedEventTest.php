<?php

use App\Analytics\SearchContextStore;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Outbox;
use App\Services\ProductSearchService;
use App\Services\Search\SearchResult;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;

function searchedResult(int $count = 3, ?string $nextCursor = null, int $total = 3): SearchResult
{
    return new SearchResult(
        items: array_map(fn (int $i) => ['id' => (string) (100 + $i), 'name' => "Produkt $i"], $count > 0 ? range(1, $count) : []),
        facets: ['brand' => [], 'category' => [], 'price' => [], 'in_stock' => []],
        total: ['value' => $total, 'isLowerBound' => false],
        nextCursor: $nextCursor,
        tookMs: 1,
    );
}

function mockSearch(SearchResult $result): void
{
    test()->mock(ProductSearchService::class, fn (MockInterface $m) => $m->shouldReceive('search')->andReturn($result));
}

test('GET /search emituje jeden user.searched ze znormalizowaną frazą i wynikami', function () {
    mockSearch(searchedResult(25, total: 87));
    $sid = (string) Str::uuid();

    $searchId = null;
    $this->withUnencryptedCookie('mp_sid', $sid)->get('/search?q=%20Buty%20&brand[]=Nike&sort=price_asc')
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$searchId) {
            $page->component('Search')->has('searchId');
            $searchId = $page->toArray()['props']['searchId'];
        });

    $rows = Outbox::where('event_type', 'user.searched')->get();
    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect($row->aggregate_type)->toBe('session')
        ->and($row->aggregate_id)->toBe($sid)
        ->and($row->sequence)->toBe(0)
        ->and(Str::isUlid($row->event_id))->toBeTrue()
        ->and($row->trace_id)->not->toBeEmpty()
        ->and($row->payload['query'])->toBe('buty')
        ->and($row->payload['query_raw'])->toBe('Buty')
        ->and($row->payload['total_hits'])->toBe(87)
        ->and($row->payload['results'])->toHaveCount(20)
        ->and($row->payload['results'][0])->toBe(101)
        ->and($row->payload['results'][19])->toBe(120)
        ->and($row->payload['sort'])->toBe('price_asc')
        ->and($row->payload['filters'])->toBe(['brand' => ['Nike']])
        ->and($row->payload['search_id'])->toBe($searchId);
});

test('kontekst wyszukiwania ląduje w Redisie z TTL', function () {
    mockSearch(searchedResult(2));
    $searchId = null;
    $this->get('/search?q=Buty')->assertInertia(function (Assert $page) use (&$searchId) {
        $searchId = $page->toArray()['props']['searchId'];
    });

    expect(app(SearchContextStore::class)->get($searchId))->toBe(['query' => 'buty', 'results' => [101, 102]])
        ->and(Redis::ttl('analytics:search:'.$searchId))->toBeGreaterThan(1700);

    Redis::del('analytics:search:'.$searchId);
});

test('żądanie z kursorem (kolejna strona) nie emituje zdarzenia', function () {
    mockSearch(searchedResult(3));

    $this->get('/search?q=buty&cursor='.base64_encode('x'))->assertOk();

    expect(Outbox::where('event_type', 'user.searched')->count())->toBe(0);
});

test('GET /api/search też emituje user.searched', function () {
    mockSearch(searchedResult(2));

    $this->getJson('/api/search?q=x')->assertOk();

    $row = Outbox::where('event_type', 'user.searched')->sole();
    expect($row->payload['query'])->toBe('x')
        ->and($row->payload['results'])->toBe([101, 102]);
});

test('zapytanie bez wyników zapisuje total_hits 0 i puste results', function () {
    mockSearch(searchedResult(0, total: 0));

    $this->get('/search?q=zzz')->assertOk();

    $row = Outbox::where('event_type', 'user.searched')->sole();
    expect($row->payload['total_hits'])->toBe(0)->and($row->payload['results'])->toBe([]);
});

test('awaria Redisa nie psuje wyszukiwania, a wiersz outboxu zostaje', function () {
    mockSearch(searchedResult(2));
    Redis::shouldReceive('setex')->andThrow(new RuntimeException('redis down'));

    $this->get('/search?q=buty')->assertOk();

    expect(Outbox::where('event_type', 'user.searched')->count())->toBe(1);
});

test('awaria zapisu do outboxu nie psuje wyszukiwania', function () {
    mockSearch(searchedResult(2));
    Outbox::creating(fn () => throw new RuntimeException('db down'));

    $this->get('/search?q=buty')->assertOk();
    $this->getJson('/api/search?q=buty')->assertOk();

    expect(Outbox::count())->toBe(0);
});

test('partial reload samego histogramu nie emituje zdarzenia', function () {
    $this->mock(ProductSearchService::class, function (MockInterface $m) {
        $m->shouldNotReceive('search');
        $m->shouldReceive('priceHistogram')->andReturn([]);
    });

    $this->get('/search?q=buty', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'Search',
        'X-Inertia-Partial-Data' => 'priceHistogram',
    ])->assertOk();

    expect(Outbox::count())->toBe(0);
});
