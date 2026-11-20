<?php

use App\Models\Product;

/**
 * /api/internal/* wymaga wspólnego sekretu w nagłówku X-Internal-Token.
 * Bazowy TestCase dokleja poprawny token do każdego żądania, więc tutaj
 * zaczynamy od czystych nagłówków (flushHeaders) i sprawdzamy odmowy.
 */
beforeEach(function () {
    $this->flushHeaders();
    Product::factory()->create();
});

dataset('internal endpoints', [
    'count' => '/api/internal/products/count',
    'ids' => '/api/internal/products/ids',
    'changed' => '/api/internal/products/changed?since=2026-01-01T00:00:00Z',
    'projections' => '/api/internal/products/projections?ids=1',
    'projection' => '/api/internal/products/1/projection',
]);

test('bez tokenu odpowiada 401', function (string $url) {
    $this->getJson($url)->assertUnauthorized();
})->with('internal endpoints');

test('z błędnym tokenem odpowiada 401', function (string $url) {
    $this->withHeader('X-Internal-Token', 'zly-token')->getJson($url)->assertUnauthorized();
})->with('internal endpoints');

test('z poprawnym tokenem przepuszcza', function () {
    $this->withHeader('X-Internal-Token', config('services.internal_api.token'))
        ->getJson('/api/internal/products/count')
        ->assertOk()
        ->assertExactJson(['count' => 1]);
});

test('pusty token w konfiguracji odmawia każdemu (fail closed), także pustemu nagłówkowi', function () {
    config(['services.internal_api.token' => '']);

    $this->withHeader('X-Internal-Token', '')->getJson('/api/internal/products/count')->assertStatus(503);
    $this->getJson('/api/internal/products/count')->assertStatus(503);
});
