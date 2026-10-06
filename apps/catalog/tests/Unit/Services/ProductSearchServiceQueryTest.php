<?php

use App\Services\ProductSearchService;
use App\Services\Search\SearchCriteria;
use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Http\Request;

/**
 * Testuje WYŁĄCZNIE budowanie DSL (metody `buildSearchQuery`/`buildFilters`/
 * `buildSort`/`buildTextQuery`) — żadna z nich nie dotyka `$this->client`,
 * więc te testy nie potrzebują żywego Elasticsearcha ani bazy danych
 * (`tests/Unit`, poza `RefreshDatabase` z `Pest.php`). Przypadki, które
 * faktycznie odpytują ES (wyniki, facety, cheapest-offer) są w
 * `tests/Feature/SearchIntegrationTest.php`.
 *
 * Klient jest budowany, ale NIGDY nie wykonuje żądania sieciowego —
 * `ClientBuilder::build()` tylko konstruuje obiekt, połączenie nawiązuje
 * się dopiero przy pierwszym realnym wywołaniu API.
 */
function searchService(): ProductSearchService
{
    return new ProductSearchService(ClientBuilder::create()->build());
}

function criteriaFromQuery(array $query): SearchCriteria
{
    return SearchCriteria::fromRequest(Request::create('/search', 'GET', $query));
}

test('puste zapytanie tekstowe buduje match_all', function () {
    $query = searchService()->buildTextQuery('');

    expect($query)->toHaveKey('match_all');
});

test('niepuste zapytanie tekstowe to multi_match po name/name.ac/brand/description', function () {
    $query = searchService()->buildTextQuery('buty do biegania');

    expect($query['multi_match']['query'])->toBe('buty do biegania')
        ->and($query['multi_match']['type'])->toBe('best_fields')
        ->and($query['multi_match']['fields'])->toBe(['name^3', 'name.ac', 'brand^2', 'description']);
});

test('filtry lądują WYŁĄCZNIE w bool.filter zapytania, nigdy w must', function () {
    $criteria = criteriaFromQuery(['q' => 'laptop', 'brand' => ['Acme'], 'in_stock' => '1']);

    $query = searchService()->buildSearchQuery($criteria);

    expect($query['bool']['must'])->toHaveCount(1)
        ->and($query['bool']['must'][0])->toHaveKey('multi_match')
        ->and($query['bool']['filter'])->toContain(['terms' => ['brand' => ['Acme']]])
        ->and($query['bool']['filter'])->toContain(['term' => ['in_stock' => true]]);

    // Upewnij się, że filtry NIE wyciekły do `must` (nie wpływałyby wtedy
    // na _score inaczej niż chcemy, i nie byłyby cache'owalne — moduł 6).
    foreach ($query['bool']['must'] as $clause) {
        expect($clause)->not->toHaveKey('term')->and($clause)->not->toHaveKey('terms');
    }
});

test('brak filtrów daje pustą listę bool.filter', function () {
    $criteria = criteriaFromQuery([]);

    $filters = searchService()->buildFilters($criteria);

    expect($filters)->toBe([]);
});

test('filtr cenowy sprawdza nakładanie się przedziału [price_min, price_max] produktu', function () {
    $criteria = criteriaFromQuery(['price_min' => '1000', 'price_max' => '5000']);

    $filters = searchService()->buildFilters($criteria);

    expect($filters)->toContain(['range' => ['price_max' => ['gte' => 1000]]])
        ->and($filters)->toContain(['range' => ['price_min' => ['lte' => 5000]]]);
});

test('exclude pomija wskazany wymiar filtra, zostawiając pozostałe', function () {
    $criteria = criteriaFromQuery(['brand' => ['Acme'], 'in_stock' => '1']);

    $withoutBrand = searchService()->buildFilters($criteria, exclude: ['brand']);
    $withoutInStock = searchService()->buildFilters($criteria, exclude: ['in_stock']);

    expect($withoutBrand)->not->toContain(['terms' => ['brand' => ['Acme']]])
        ->and($withoutBrand)->toContain(['term' => ['in_stock' => true]])
        ->and($withoutInStock)->toContain(['terms' => ['brand' => ['Acme']]])
        ->and($withoutInStock)->not->toContain(['term' => ['in_stock' => true]]);
});

test('sortowanie po cenie i dacie ma stabilny tie-breaker _shard_doc, nie _id', function () {
    $service = searchService();

    expect($service->buildSort(criteriaFromQuery(['sort' => 'price_asc'])))
        ->toBe([['price_min' => 'asc'], ['_shard_doc' => 'asc']])
        ->and($service->buildSort(criteriaFromQuery(['sort' => 'newest'])))
        ->toBe([['created_at' => 'desc'], ['_shard_doc' => 'asc']])
        ->and($service->buildSort(criteriaFromQuery([])))
        ->toBe([['_score' => 'desc'], ['_shard_doc' => 'asc']]);
});

test('nieznana wartość sort z requestu spada na relevance, nie wywala się', function () {
    $criteria = criteriaFromQuery(['sort' => 'najlepszy-produkt-swiata']);

    expect($criteria->sort)->toBe('relevance');
});

test('SearchCriteria::fromRequest przycina i filtruje puste wartości brand[]', function () {
    $criteria = criteriaFromQuery(['brand' => ['Acme', '', 'Globex']]);

    expect($criteria->brands)->toBe(['Acme', 'Globex']);
});
