<?php

use App\Models\Category;
use App\Models\Offer;
use App\Models\Outbox;
use App\Models\Product;
use App\Models\User;
use App\Services\Search\IndexedProductReader;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;

/**
 * „Droga zdarzenia” na stronie edycji produktu. ES jest mockiem
 * (`IndexedProductReader`), więc testy sprawdzają tylko porównanie
 * `_version` dokumentu z `occurred_at` zdarzeń i stronę Postgresa.
 */
function traceEvent(string $type, string $id, array $attrs = []): Outbox
{
    return Outbox::create(array_merge([
        'event_id' => (string) Str::ulid(),
        'aggregate_type' => $type,
        'aggregate_id' => $id,
        'event_type' => "{$type}.updated",
        'payload' => [],
        'sequence' => 1,
        'occurred_at' => now(),
        'published_at' => now(),
    ], $attrs));
}

function readerReturns(?array $document): void
{
    test()->mock(IndexedProductReader::class, function (MockInterface $mock) use ($document) {
        $mock->shouldReceive('find')->andReturn($document);
    });
}

function traceOf(Product $product): array
{
    $trace = null;

    test()->actingAs(User::factory()->admin()->create())
        ->get("/panel/products/{$product->id}")
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$trace) {
            $trace = $page->toArray()['props']['trace'];
        });

    return $trace;
}

// Ułamek sekundy celowo: `occurred_at` ucięte do pełnych sekund zapaliłoby
// krok „w ES” za wcześnie dla dokumentu z tej samej sekundy.
function occurredAt(): Carbon
{
    return Carbon::parse('2026-10-11 12:00:00.250000', 'UTC');
}

function micros(Carbon $at): int
{
    return (int) $at->format('Uu');
}

test('zdarzenie po wersji dokumentu nie jest jeszcze w ES', function () {
    $product = Product::factory()->create();
    traceEvent('product', (string) $product->id, ['occurred_at' => occurredAt()]);
    readerReturns(['version' => micros(occurredAt()) - 1, 'source' => []]);

    $trace = traceOf($product);

    expect($trace['es_available'])->toBeTrue()
        ->and($trace['events'][0]['in_es'])->toBeFalse()
        ->and($trace['pending'])->toBeTrue();
});

test('zdarzenie przed wersją dokumentu jest w ES', function () {
    $product = Product::factory()->create();
    traceEvent('product', (string) $product->id, ['occurred_at' => occurredAt()]);
    readerReturns(['version' => micros(occurredAt()) + 1, 'source' => []]);

    $trace = traceOf($product);

    expect($trace['events'][0]['in_es'])->toBeTrue()
        ->and($trace['pending'])->toBeFalse();
});

test('nieopublikowane zdarzenie', function () {
    $product = Product::factory()->create();
    traceEvent('product', (string) $product->id, ['published_at' => null]);
    readerReturns(null);

    $trace = traceOf($product);

    expect($trace['events'][0]['published'])->toBeFalse()
        ->and($trace['events'][0])->toHaveKeys(['id', 'type', 'occurred_at', 'published', 'in_es']);
});

test('bierze zdarzenia produktu i jego ofert, max 10, najnowsze pierwsze', function () {
    $product = Product::factory()->create();
    [$first, $second] = Offer::factory()->count(2)->create(['product_id' => $product->id]);
    $foreign = Offer::factory()->create();
    Outbox::query()->delete();

    $ids = [];
    foreach (range(1, 12) as $i) {
        $ids[] = match ($i % 3) {
            0 => traceEvent('product', (string) $product->id)->id,
            1 => traceEvent('offer', (string) $first->id)->id,
            2 => traceEvent('offer', (string) $second->id)->id,
        };
    }
    $foreignId = traceEvent('offer', (string) $foreign->id)->id;
    readerReturns(null);

    $trace = traceOf($product);

    expect(array_column($trace['events'], 'id'))
        ->toBe(array_slice(array_reverse($ids), 0, 10))
        ->not->toContain($foreignId);
});

test('ES nieosiągalny', function () {
    $product = Product::factory()->create();
    traceEvent('product', (string) $product->id);
    $this->mock(IndexedProductReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('find')->andThrow(new NoNodeAvailableException('No alive nodes'));
    });

    $trace = traceOf($product);

    expect($trace['es_available'])->toBeFalse()
        ->and($trace['events'][0]['in_es'])->toBeNull()
        ->and($trace['pending'])->toBeFalse()
        ->and(array_column($trace['comparison'], 'es'))->each->toBeNull();
});

test('porównanie wyróżnia różną cenę', function () {
    $parent = Category::factory()->create(['name' => 'Odzież']);
    $child = Category::factory()->create(['name' => 'Buty', 'parent_id' => $parent->id]);
    $child->update(['path' => "{$parent->id}.{$child->id}"]);
    $product = Product::factory()->create(['name' => 'But', 'category_id' => $child->id]);
    Offer::factory()->create(['product_id' => $product->id, 'price_cents' => 300, 'stock' => 2]);
    // Oferta bez stanu nie jest widoczna (`listed()`) i nie liczy się do ceny, jak w projekcji.
    Offer::factory()->create(['product_id' => $product->id, 'price_cents' => 100, 'stock' => 0]);
    readerReturns(['version' => 1, 'source' => [
        'name' => 'But',
        'category' => ['id' => (string) $child->id, 'path' => "{$parent->id}.{$child->id}"],
        'price_min' => 500,
        'in_stock' => true,
    ]]);

    $comparison = collect(traceOf($product)['comparison'])->keyBy('field');

    expect($comparison['price_min'])->toMatchArray(['postgres' => 300, 'es' => 500, 'differs' => true])
        ->and($comparison['name']['differs'])->toBeFalse()
        ->and($comparison['category'])->toMatchArray(['postgres' => 'Odzież › Buty', 'es' => 'Odzież › Buty', 'differs' => false])
        ->and($comparison['in_stock'])->toMatchArray(['postgres' => true, 'es' => true, 'differs' => false]);
});

test('partial reload trace pyta ES raz i zwraca tylko trace', function () {
    $product = Product::factory()->create();
    $this->mock(IndexedProductReader::class, function (MockInterface $mock) {
        $mock->shouldReceive('find')->twice()->andReturn(null);
    });

    $this->actingAs(User::factory()->admin()->create())
        ->get("/panel/products/{$product->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->reloadOnly('trace', fn (Assert $reload) => $reload
                ->has('trace')
                ->missing('offers')
                ->missing('categories')));
});
