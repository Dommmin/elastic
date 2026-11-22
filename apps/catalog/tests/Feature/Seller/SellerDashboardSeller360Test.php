<?php

use App\Models\Seller;
use App\Models\User;
use App\Seller360\SellerStatsReader;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeEsTransport;

/**
 * Blok „Twoje statystyki” (ETAP 13, Task 15): id sprzedawcy pochodzi z sesji, nigdy z żądania,
 * a w propsach ląduje wyłącznie biała lista z `seller-360-public`.
 */
function sellerWithDoc(): array
{
    $user = User::factory()->create();
    $seller = Seller::factory()->forUser($user)->create();

    return [$user, $seller];
}

function fakeStatsReader(FakeEsTransport $es): void
{
    app()->instance(SellerStatsReader::class, new SellerStatsReader($es->client()));
}

function docFor(string $id, array $extra = []): array
{
    $meta = ['version' => 1, 'source_ts' => '2026-11-22T09:00:00Z', 'updated_at' => '2026-11-22T09:00:00Z', 'status' => 'ok'];

    return [
        'seller_id' => $id, 'nip' => '1234563218', 'region' => 'mazowieckie', 'synthetic' => false,
        'profile' => ['name' => "Sklep {$id}", 'city' => 'Łódź', 'status' => 'active', 'status_reason' => null, '_meta' => $meta],
        'offers' => ['published' => 4, '_meta' => $meta],
        'sales' => ['gmv_30d_cents' => 5000, '_meta' => $meta],
        'registry' => ['courier' => ['id' => 'c', 'on_time_rate' => 0.9, 'rated_at' => '2026-11-22T08:00:00Z'], '_meta' => $meta],
        ...$extra,
    ];
}

test('sprzedawca A dostaje tylko dokument A, nawet gdy żądanie podsuwa cudzy seller_id', function () {
    [$user, $seller] = sellerWithDoc();
    $es = new FakeEsTransport(function ($request) {
        $id = basename($request->getUri()->getPath());

        return [200, ['_source' => docFor($id)]];
    });
    fakeStatsReader($es);

    $this->actingAs($user)->get('/seller?seller_id=999&id=999')
        ->assertInertia(fn (Assert $page) => $page
            ->component('seller/Dashboard')
            ->loadDeferredProps(fn (Assert $page) => $page
                ->where('seller360.profile.name', "Sklep {$seller->id}")
                ->where('seller360.courier_on_time_rate', 0.9)
                ->has('seller360.freshness.profile')));

    expect($es->log)->toBe(["GET /seller-360-public/_doc/{$seller->id}"]);
});

test('props nie zawierają billing, overdue, recent_texts, nip ani region', function () {
    [$user] = sellerWithDoc();
    // Dokument „zepsuty” (np. zła alias): obrona w głąb po stronie czytnika.
    $es = new FakeEsTransport(fn () => [200, ['_source' => docFor('1', [
        'billing' => ['overdue_cents' => 900, '_meta' => ['updated_at' => 'x']],
        'complaints' => ['recent_texts' => ['uszkodzony'], '_meta' => ['updated_at' => 'x']],
    ])]]);
    fakeStatsReader($es);

    $response = $this->actingAs($user)->get('/seller');
    $initial = $response->getContent();
    $version = '';
    $response->assertInertia(function (Assert $page) use (&$version) {
        $version = $page->toArray()['version'] ?? '';
    });
    // Żądanie częściowe tak, jak robi je przeglądarka po pierwszym malowaniu (deferred props).
    $deferred = json_encode($this->actingAs($user)->get('/seller', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'seller/Dashboard',
        'X-Inertia-Partial-Data' => 'seller360',
    ])->assertOk()->json('props.seller360'));

    expect(json_decode($deferred, true))->toHaveKeys(['profile', 'offers', 'sales', 'courier_on_time_rate', 'freshness'])
        ->and(array_keys(json_decode($deferred, true)))->toBe(['profile', 'offers', 'sales', 'courier_on_time_rate', 'freshness']);

    foreach ([$initial, $deferred] as $payload) {
        foreach (['billing', 'overdue', 'recent_texts', 'complaints', '1234563218', 'mazowieckie', '_meta'] as $leak) {
            expect($payload)->not->toContain($leak);
        }
    }
    expect($deferred)->toContain('Sklep 1');
});

test('przy awarii ES strona zwraca 200, a seller360 to null', function () {
    [$user] = sellerWithDoc();
    Exceptions::fake();
    fakeStatsReader(new FakeEsTransport(fn () => throw new RuntimeException('ES leży')));

    $this->actingAs($user)->get('/seller')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('seller/Dashboard')
            ->loadDeferredProps(fn (Assert $page) => $page->where('seller360', null)));

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'ES leży');
});
