<?php

use App\Models\Outbox;
use Illuminate\Support\Str;

/**
 * `GET /api/internal/products/changed` (ETAP 10, Task 12b): ID produktów
 * dotkniętych od `since` według outboxu — ograniczone nadrabianie po
 * reindex-abort zamiast pełnego backfillu. Ta sama reguła co
 * `search:verify-convergence` (wspólny resolver).
 */
function changedEvent(string $type, string $aggregateId, array $payload = [], ?string $at = null): void
{
    Outbox::create([
        'event_id' => (string) Str::ulid(),
        'aggregate_type' => $type,
        'aggregate_id' => $aggregateId,
        'event_type' => $type.'.updated',
        'payload' => $payload,
        'sequence' => 1,
        'occurred_at' => $at ?? now(),
    ]);
}

test('product, offer i review dają ID produktu, inne typy nic', function () {
    changedEvent('product', '30');
    changedEvent('offer', '900', ['product_id' => 10]);
    changedEvent('review', '901', ['product_id' => 20]);
    changedEvent('seller', '5', ['product_id' => 99]);
    changedEvent('category', '6');
    changedEvent('offer', '902', ['product_id' => 10]);

    $this->getJson('/api/internal/products/changed?since='.urlencode(now()->subHour()->toIso8601String()))
        ->assertOk()
        ->assertExactJson(['ids' => [10, 20, 30], 'next_after' => null]);
});

test('usunięty produkt też jest na liście', function () {
    Outbox::create([
        'event_id' => (string) Str::ulid(),
        'aggregate_type' => 'product',
        'aggregate_id' => '77',
        'event_type' => 'product.deleted',
        'payload' => [],
        'sequence' => 2,
        'occurred_at' => now(),
    ]);

    $this->getJson('/api/internal/products/changed?since='.urlencode(now()->subMinute()->toIso8601String()))
        ->assertOk()
        ->assertJsonPath('ids', [77]);
});

test('since odcina starsze zdarzenia', function () {
    changedEvent('product', '1', at: now()->subHours(2)->toDateTimeString());
    changedEvent('product', '2', at: now()->subMinutes(5)->toDateTimeString());

    $this->getJson('/api/internal/products/changed?since='.urlencode(now()->subHour()->toIso8601String()))
        ->assertOk()
        ->assertJsonPath('ids', [2]);
});

test('stronicuje po ID produktu bez dubli i luk', function () {
    foreach ([5, 3, 9, 1, 7] as $id) {
        changedEvent('product', (string) $id);
        changedEvent('offer', (string) (100 + $id), ['product_id' => $id]);
    }
    $since = urlencode(now()->subHour()->toIso8601String());

    $seen = [];
    $after = null;
    $pages = 0;
    do {
        $json = $this->getJson("/api/internal/products/changed?since={$since}&limit=2".($after !== null ? "&after={$after}" : ''))->assertOk()->json();
        $seen = [...$seen, ...$json['ids']];
        $after = $json['next_after'];
        $pages++;
    } while ($after !== null);

    expect($seen)->toBe([1, 3, 5, 7, 9])->and($pages)->toBe(3);
});

test('błędne parametry dają 422', function (string $query) {
    $this->getJson('/api/internal/products/changed'.$query)->assertStatus(422);
})->with([
    'brak since' => [''],
    'zła data' => ['?since=wczoraj'],
    'limit 0' => ['?since=2026-11-19T10:00:00Z&limit=0'],
    'limit 5001' => ['?since=2026-11-19T10:00:00Z&limit=5001'],
    'after ujemne' => ['?since=2026-11-19T10:00:00Z&after=-1'],
]);
