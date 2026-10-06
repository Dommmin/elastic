<?php

use Elastic\Elasticsearch\Client;

/**
 * Testy trasowania/kontraktu odpowiedzi — logika zapytań jest już pokryta
 * w `tests/Unit/Services/ProductSearchServiceQueryTest.php` (DSL) i
 * `tests/Feature/SearchIntegrationTest.php` (żywy ES). Tu sprawdzamy tylko,
 * że kontrolery poprawnie delegują do `ProductSearchService` i zwracają
 * właściwy kształt odpowiedzi (D-09: kontrolery jako cienkie adaptery).
 */
beforeEach(function () {
    /** @var Client $client */
    $client = app(Client::class);

    try {
        $client->count(['index' => 'products-search']);
    } catch (Throwable $e) {
        test()->markTestSkipped('Elasticsearch nieosiągalny: '.$e->getMessage());
    }
});

test('GET /search renderuje stronę Inertii z propsami results/facets/filters', function () {
    $response = $this->get('/search?q=smartfon');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Search')
        ->has('results.data')
        ->has('facets')
        ->where('filters.q', 'smartfon')
    );
});

test('GET /api/search zwraca JSON z items/facets/total/filters', function () {
    $response = $this->getJson('/api/search?q=laptop&per_page=3');

    $response->assertOk()
        ->assertJsonStructure(['items', 'facets', 'total', 'next_cursor', 'took_ms', 'filters'])
        ->assertJsonPath('filters.q', 'laptop');
});

test('GET /api/suggest zwraca listę podpowiedzi', function () {
    $response = $this->getJson('/api/suggest?q=lapt');

    $response->assertOk()->assertJsonStructure(['suggestions' => [['id', 'name', 'brand']]]);
});

test('GET /api/suggest z pustym q zwraca pustą listę, nie błąd', function () {
    $this->getJson('/api/suggest?q=')
        ->assertOk()
        ->assertExactJson(['suggestions' => []]);
});

test('zepsuty kursor z URL-a daje 400, nie 500 (API i strona)', function () {
    // Ucięty przy kopiowaniu linku albo ręcznie zmieniony — to dane od
    // użytkownika, nie błąd serwera (InvalidSearchCursorException).
    $this->getJson('/api/search?q=laptop&cursor=to-nie-jest-kursor')->assertStatus(400);
    $this->get('/search?q=laptop&cursor='.base64_encode('{"pit":123}'))->assertStatus(400);
});
