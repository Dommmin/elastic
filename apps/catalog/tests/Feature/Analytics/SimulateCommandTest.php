<?php

use App\Analytics\Simulation\QueryPool;
use App\Analytics\Simulation\SimulatedEventPublisher;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Product;
use App\Services\ProductSearchService;
use App\Services\Search\SearchCriteria;
use App\Services\Search\SearchResult;
use Carbon\CarbonImmutable;
use Mockery\MockInterface;

/**
 * Publisher bez RabbitMQ: zbiera koperty w pamięci.
 */
class FakeSimulatedEventPublisher extends SimulatedEventPublisher
{
    /** @var list<array<string, mixed>> */
    public array $envelopes = [];

    public function publish(array $envelope): void
    {
        $this->envelopes[] = $envelope;
    }

    public function finish(): int
    {
        return count($this->envelopes);
    }
}

/**
 * Mały katalog (2 marki × 2 kategorie × 6 produktów) i wyszukiwarka-atrapa:
 * frazy spoza katalogu dają zero wyników, reszta stałą listę produktów.
 */
function simulationCatalog(bool $unstableTies = false): void
{
    $categories = [Category::factory()->create(['name' => 'Laptopy']), Category::factory()->create(['name' => 'Rowery'])];
    $brands = [Brand::factory()->create(['name' => 'Acme']), Brand::factory()->create(['name' => 'Zeta Group'])];

    foreach ($brands as $brand) {
        foreach ($categories as $category) {
            foreach (range(1, 6) as $i) {
                $product = Product::factory()->create([
                    'brand_id' => $brand->id,
                    'category_id' => $category->id,
                    'name' => $brand->name.' '.($category->name === 'Laptopy' ? 'Laptop Nova' : 'Rower górski Alpha').' '.$i,
                ]);
                Offer::factory()->create(['product_id' => $product->id, 'price_cents' => 20_000 + $i]);
                Offer::factory()->create(['product_id' => $product->id, 'price_cents' => 10_000 + $i]);
                Offer::factory()->soldOut()->create(['product_id' => $product->id, 'price_cents' => 100]);
            }
        }
    }

    $ids = Product::query()->orderBy('id')->pluck('id')->all();

    test()->mock(ProductSearchService::class, function (MockInterface $m) use ($ids, $unstableTies) {
        $calls = new stdClass;
        $calls->n = 0;
        $m->shouldReceive('search')->andReturnUsing(function (SearchCriteria $c) use ($ids, $unstableTies, $calls): SearchResult {
            $hits = in_array(mb_strtolower($c->q), QueryPool::OUT_OF_CATALOG, true) ? [] : $ids;
            if ($unstableTies && $hits !== []) {
                // Jak remisy w ES: kolejność zmienia się między wywołaniami (kopie shardów).
                $shift = ++$calls->n % count($hits);
                $hits = [...array_slice($hits, $shift), ...array_slice($hits, 0, $shift)];
            }

            return new SearchResult(
                items: array_map(fn (int $id) => ['id' => (string) $id], array_slice($hits, 0, $c->perPage)),
                facets: [],
                total: ['value' => count($hits), 'isLowerBound' => false],
                nextCursor: null,
                tookMs: 1,
            );
        });
    });
}

/**
 * @return list<array<string, mixed>>
 */
function simulate(array $options): array
{
    $fake = new FakeSimulatedEventPublisher;
    app()->instance(SimulatedEventPublisher::class, $fake);

    test()->artisan('analytics:simulate', $options)->assertSuccessful();

    return $fake->envelopes;
}

function ofType(array $envelopes, string $type): array
{
    return array_values(array_filter($envelopes, fn (array $e) => $e['type'] === $type));
}

test('jeden dzień symulacji daje wszystkie 4 typy w kopercie outboxu, w obrębie wskazanego dnia', function () {
    simulationCatalog();

    $events = simulate(['--days' => 1, '--sessions-per-day' => 300, '--as-of' => '2026-11-10', '--seed' => 1]);

    foreach (['user.searched', 'user.clicked_result', 'user.added_to_cart', 'user.checked_out'] as $type) {
        expect(ofType($events, $type))->not->toBeEmpty();
    }

    $from = CarbonImmutable::parse('2026-11-09 00:00:00', 'UTC');
    $to = CarbonImmutable::parse('2026-11-10 00:00:00', 'UTC');
    foreach ($events as $e) {
        $at = CarbonImmutable::parse($e['occurred_at']);
        expect(array_keys($e))->toBe(['id', 'type', 'version', 'source', 'occurred_at', 'aggregate', 'sequence', 'data'])
            ->and($e['aggregate']['type'])->toBe('session')
            ->and($e['sequence'])->toBe(0)
            ->and($e['source'])->toBe('catalog')
            ->and($at->greaterThanOrEqualTo($from) && $at->lessThan($to))->toBeTrue();
    }

    expect(count(array_unique(array_map(fn ($e) => $e['aggregate']['id'], ofType($events, 'user.searched')))))->toBe(300);
});

test('kształt data zgadza się z UI: searched, clicked (dedup_key), added_to_cart (najtańsza oferta), checked_out', function () {
    simulationCatalog();

    $events = simulate(['--days' => 1, '--sessions-per-day' => 300, '--as-of' => '2026-11-10', '--seed' => 2]);

    $searchedById = [];
    foreach (ofType($events, 'user.searched') as $s) {
        expect(array_keys($s['data']))->toContain('search_id', 'query', 'query_raw', 'filters', 'total_hits', 'results', 'sort')
            ->and($s['data']['query'])->toBe(mb_strtolower(trim($s['data']['query_raw'])))
            ->and(count($s['data']['results']))->toBeLessThanOrEqual(20)
            ->and($s['data']['sort'])->toBe('relevance');
        $searchedById[$s['data']['search_id']] = $s;
    }

    foreach (ofType($events, 'user.clicked_result') as $c) {
        $d = $c['data'];
        $search = $searchedById[$d['search_id']];
        expect($d['dedup_key'])->toBe("click:{$d['search_id']}:{$d['product_id']}")
            ->and($d['query'])->toBe($search['data']['query'])
            ->and($search['data']['results'][$d['position'] - 1])->toBe($d['product_id'])
            ->and($c['aggregate']['id'])->toBe($search['aggregate']['id']);
    }

    foreach (ofType($events, 'user.added_to_cart') as $a) {
        $cheapest = Offer::query()->listed()->where('product_id', $a['data']['product_id'])->orderBy('price_cents')->value('id');
        expect($a['data']['offer_id'])->toBe($cheapest)
            ->and($a['data'])->toHaveKeys(['search_id', 'query', 'user_id']);
    }

    foreach (ofType($events, 'user.checked_out') as $o) {
        expect($o['data'])->toHaveKeys(['order_ids', 'product_ids', 'amount_cents', 'user_id'])
            ->and(min($o['data']['order_ids']))->toBeGreaterThanOrEqual(900_000_000)
            ->and($o['data']['amount_cents'])->toBeGreaterThan(0);
    }
});

test('to samo ziarno daje te same zdarzenia, a --day=N to dokładnie dzień N pełnego przebiegu', function () {
    simulationCatalog();

    $full = simulate(['--days' => 2, '--sessions-per-day' => 40, '--as-of' => '2026-11-10', '--seed' => 5]);
    $again = simulate(['--days' => 2, '--sessions-per-day' => 40, '--as-of' => '2026-11-10', '--seed' => 5]);
    $day2 = simulate(['--days' => 2, '--day' => 2, '--sessions-per-day' => 40, '--as-of' => '2026-11-10', '--seed' => 5]);
    $other = simulate(['--days' => 2, '--sessions-per-day' => 40, '--as-of' => '2026-11-10', '--seed' => 6]);

    $secondDay = array_values(array_filter($full, fn ($e) => str_starts_with($e['occurred_at'], '2026-11-09')));

    expect($again)->toBe($full)
        ->and($day2)->toBe($secondDay)
        ->and($secondDay)->not->toBeEmpty()
        ->and($other)->not->toBe($full);
});

test('zmienna kolejność remisów w ES nie zmienia sesji, czasów ani ID wyszukań', function () {
    simulationCatalog(unstableTies: true);

    $strip = fn (array $events) => array_map(
        fn (array $e) => [$e['id'], $e['occurred_at'], $e['aggregate']['id'], $e['data']['search_id'], $e['data']['query']],
        ofType($events, 'user.searched'),
    );

    $first = simulate(['--days' => 1, '--sessions-per-day' => 100, '--as-of' => '2026-11-10', '--seed' => 9]);
    $second = simulate(['--days' => 1, '--sessions-per-day' => 100, '--as-of' => '2026-11-10', '--seed' => 9]);

    expect(array_column(array_column(ofType($second, 'user.searched'), 'data'), 'results'))
        ->not->toBe(array_column(array_column(ofType($first, 'user.searched'), 'data'), 'results'))
        ->and($strip($second))->toBe($strip($first));
});

test('--inject=zero-results: w ostatniej godzinie co najmniej połowa wyszukań bez wyników', function () {
    simulationCatalog();

    $events = simulate(['--days' => 1, '--sessions-per-day' => 300, '--as-of' => '2026-11-10', '--seed' => 3, '--inject' => 'zero-results']);

    $lastHour = array_filter(ofType($events, 'user.searched'), fn ($e) => $e['occurred_at'] >= '2026-11-09T23:00:00');
    $zero = array_filter($lastHour, fn ($e) => $e['data']['total_hits'] === 0 && $e['data']['results'] === []);

    expect(count($lastHour))->toBeGreaterThan(5)
        ->and(count($zero) / count($lastHour))->toBeGreaterThanOrEqual(0.5);
});

test('--live: wszystkie zdarzenia w ostatnich 15 minutach', function () {
    simulationCatalog();
    $now = CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC');
    $this->travelTo($now);

    $events = simulate(['--live' => true, '--sessions-per-day' => 30, '--seed' => 4]);

    expect($events)->not->toBeEmpty();
    foreach ($events as $e) {
        $at = CarbonImmutable::parse($e['occurred_at']);
        expect($at->greaterThanOrEqualTo($now->subMinutes(15)) && $at->lessThanOrEqualTo($now))->toBeTrue();
    }
});

test('błędna opcja --inject kończy się błędem', function () {
    app()->instance(SimulatedEventPublisher::class, new FakeSimulatedEventPublisher);

    $this->artisan('analytics:simulate', ['--inject' => 'cos'])->assertFailed();
});

test('na produkcji symulator odmawia bez --force', function () {
    $publisher = new FakeSimulatedEventPublisher;
    app()->instance(SimulatedEventPublisher::class, $publisher);
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('analytics:simulate', ['--live' => true, '--sessions-per-day' => 1])
        ->expectsOutputToContain('--force')
        ->assertFailed();

    expect($publisher->envelopes)->toBeEmpty();
});

test('na produkcji z --force symulator działa', function () {
    simulationCatalog();
    app()->detectEnvironment(fn () => 'production');

    expect(simulate(['--live' => true, '--sessions-per-day' => 5, '--force' => true]))->not->toBeEmpty();
});
