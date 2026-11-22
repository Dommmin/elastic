<?php

use App\Seller360\SellerStatsReader;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeEsTransport;

uses(Tests\TestCase::class);

function publicDoc(array $extra = []): array
{
    $meta = fn (string $at) => ['version' => 3, 'source_ts' => $at, 'updated_at' => $at, 'status' => 'ok'];

    return [
        'seller_id' => '7',
        'nip' => '1234563218',
        'region' => 'mazowieckie',
        'synthetic' => false,
        'doc_updated_at' => '2026-11-22T10:00:00Z',
        'profile' => ['name' => 'Sklep Siedem', 'city' => 'Warszawa', 'status' => 'active', 'status_reason' => null, '_meta' => $meta('2026-11-22T09:00:00Z')],
        'offers' => ['published' => 12, 'draft' => 1, '_meta' => $meta('2026-11-22T09:10:00Z')],
        'sales' => ['gmv_30d_cents' => 123400, '_meta' => $meta('2026-11-22T09:20:00Z')],
        'registry' => ['legal_name' => 'Siedem sp. z o.o.', 'courier' => ['id' => 'c-1', 'on_time_rate' => 0.93, 'rated_at' => '2026-11-22T08:00:00Z'], '_meta' => $meta('2026-11-22T09:30:00Z')],
        ...$extra,
    ];
}

test('forSeller czyta tylko dokument seller-360-public tego sprzedawcy i składa widok bez _meta', function () {
    $es = new FakeEsTransport(fn () => [200, ['_id' => '7', 'found' => true, '_source' => publicDoc()]]);

    $view = (new SellerStatsReader($es->client()))->forSeller(7);

    expect($es->log)->toBe(['GET /seller-360-public/_doc/7'])
        ->and($view)->toBe([
            'profile' => ['name' => 'Sklep Siedem', 'city' => 'Warszawa', 'status' => 'active', 'status_reason' => null],
            'offers' => ['published' => 12, 'draft' => 1],
            'sales' => ['gmv_30d_cents' => 123400],
            'courier_on_time_rate' => 0.93,
            'freshness' => [
                'profile' => '2026-11-22T09:00:00Z',
                'offers' => '2026-11-22T09:10:00Z',
                'sales' => '2026-11-22T09:20:00Z',
                'courier' => '2026-11-22T09:30:00Z',
            ],
        ]);
});

test('widok nie niesie nip, region, synthetic ani sekcji spoza białej listy', function () {
    $es = new FakeEsTransport(fn () => [200, ['_source' => publicDoc([
        'billing' => ['overdue_cents' => 500, '_meta' => ['updated_at' => 'x']],
        'complaints' => ['recent_texts' => ['uszkodzony'], '_meta' => ['updated_at' => 'x']],
    ])]]);

    $json = json_encode((new SellerStatsReader($es->client()))->forSeller(7));

    foreach (['billing', 'overdue', 'complaints', 'recent_texts', 'nip', '1234563218', 'region', 'synthetic', 'legal_name', '_meta'] as $leak) {
        expect($json)->not->toContain($leak);
    }
});

test('404 daje null i nie jest błędem', function () {
    Log::spy();
    $es = new FakeEsTransport(fn () => [404, ['_id' => '9', 'found' => false]]);

    expect((new SellerStatsReader($es->client()))->forSeller(9))->toBeNull();
    Log::shouldNotHaveReceived('error');
});

test('brak sekcji w dokumencie daje null w jej miejscu, nie wyjątek', function () {
    $es = new FakeEsTransport(fn () => [200, ['_source' => ['seller_id' => '7', 'profile' => ['name' => 'X', 'city' => 'Y', 'status' => 'active', 'status_reason' => null, '_meta' => ['updated_at' => 't']]]]]);

    $view = (new SellerStatsReader($es->client()))->forSeller(7);

    expect($view['offers'])->toBeNull()
        ->and($view['sales'])->toBeNull()
        ->and($view['courier_on_time_rate'])->toBeNull()
        ->and($view['freshness'])->toBe(['profile' => 't', 'offers' => null, 'sales' => null, 'courier' => null]);
});

test('wyjątek transportu daje null i trafia do report()', function () {
    Exceptions::fake();
    $es = new FakeEsTransport(fn () => throw new RuntimeException('ES leży'));

    expect((new SellerStatsReader($es->client()))->forSeller(7))->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});
