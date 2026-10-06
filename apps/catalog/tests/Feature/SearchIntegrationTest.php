<?php

use App\Services\ProductSearchService;
use App\Services\Search\SearchCriteria;
use Elastic\Elasticsearch\Client;
use Illuminate\Http\Request;

/**
 * Testy E2E na ŻYWYM Elasticsearchu (indeks `products-search`, dane z
 * `php artisan marketplace:seed` — ETAP 7, Faza 1). SQLite-w-pamięci nie
 * mogłoby tego złapać, tak samo jak w ETAPIE 6 (README, RUNBOOK #018) —
 * to test PRAWDZIWEGO zapytania do PRAWDZIWEGO klastra, nie logiki PHP.
 *
 * Guard: jeśli ES jest nieosiągalny (świeży checkout bez `docker compose up`)
 * albo indeks jest pusty (brak `marketplace:seed`), testy pomijają się
 * z czytelnym komunikatem zamiast failować w niejasny sposób.
 */
beforeEach(function () {
    /** @var Client $client */
    $client = app(Client::class);

    try {
        $count = $client->count(['index' => 'products-search'])->asArray()['count'];
    } catch (Throwable $e) {
        test()->markTestSkipped('Elasticsearch nieosiągalny: '.$e->getMessage());
    }

    if ($count < 50) {
        test()->markTestSkipped(
            "Indeks products-search ma tylko {$count} dokumentów — uruchom `make seed` (albo `php artisan marketplace:seed`) przed tym testem.",
        );
    }
});

function service(): ProductSearchService
{
    return app(ProductSearchService::class);
}

function criteria(array $query): SearchCriteria
{
    return SearchCriteria::fromRequest(Request::create('/search', 'GET', $query));
}

test('wyszukiwanie tekstowe zwraca trafienia i liczy total', function () {
    $result = service()->search(criteria(['q' => 'buty do biegania']));

    expect($result->items)->not->toBeEmpty()
        ->and($result->total['value'])->toBeGreaterThan(0)
        ->and($result->items[0])->toHaveKeys(['id', 'name', 'brand', 'price_min', 'cheapest_offer']);
});

test('każdy wynik ma cheapest_offer z inner_hits, z ceną równą minimum ofert', function () {
    $result = service()->search(criteria(['q' => '']));

    foreach (array_slice($result->items, 0, 5) as $item) {
        expect($item['cheapest_offer'])->not->toBeNull()
            ->and($item['cheapest_offer']['price'])->toBe($item['price_min']);
    }
});

test('filtr brand zawęża wyniki, ale facet brand nadal pokazuje wszystkie marki', function () {
    $unfiltered = service()->search(criteria(['q' => '']));
    $someBrand = $unfiltered->facets['brand'][0]['key'];

    $filtered = service()->search(criteria(['brand' => [$someBrand]]));

    expect(collect($filtered->items)->every(fn ($item) => $item['brand'] === $someBrand))->toBeTrue()
        ->and(count($filtered->facets['brand']))->toBeGreaterThan(1);
});

test('filtr ceny pokazuje tylko produkty z ofertą w podanym przedziale', function () {
    $result = service()->search(criteria(['price_min' => '10000', 'price_max' => '50000']));

    foreach ($result->items as $item) {
        expect($item['price_max'])->toBeGreaterThanOrEqual(10000)
            ->and($item['price_min'])->toBeLessThanOrEqual(50000);
    }
});

test('search_after (PIT) stronicuje bez duplikatów i bez pominięć na granicy strony', function () {
    $page1 = service()->search(criteria(['q' => '', 'per_page' => '10']));
    expect($page1->nextCursor)->not->toBeNull();

    $page2 = service()->search(criteria(['q' => '', 'per_page' => '10', 'cursor' => $page1->nextCursor]));

    $ids1 = array_column($page1->items, 'id');
    $ids2 = array_column($page2->items, 'id');

    expect(array_intersect($ids1, $ids2))->toBe([])
        ->and($ids2)->toHaveCount(10);
});

test('kolejne strony NIE liczą facetów od nowa (agregacje tylko dla pierwszej)', function () {
    $page1 = service()->search(criteria(['q' => '', 'per_page' => '10']));
    $page2 = service()->search(criteria(['q' => '', 'per_page' => '10', 'cursor' => $page1->nextCursor]));

    expect($page1->facets['brand'])->not->toBeEmpty()
        ->and($page2->facets['brand'])->toBeEmpty()
        ->and($page2->items)->toHaveCount(10);
});

test('przewinięcie po wygaśnięciu PIT nie kończy się błędem, tylko kontynuuje od tego samego miejsca', function () {
    // RUNBOOK #023: użytkownik czytał wyniki dłużej niż PIT_KEEP_ALIVE.
    // Zamykamy PIT ręcznie — ten sam efekt co wygaśnięcie, bez czekania minuty.
    $page1 = service()->search(criteria(['q' => '', 'per_page' => '10']));
    $pitId = json_decode(base64_decode($page1->nextCursor), true)['pit'];
    app(Client::class)->closePointInTime(['body' => ['id' => $pitId]]);

    $page2 = service()->search(criteria(['q' => '', 'per_page' => '10', 'cursor' => $page1->nextCursor]));

    $ids1 = array_column($page1->items, 'id');
    $ids2 = array_column($page2->items, 'id');

    expect($ids2)->toHaveCount(10)
        ->and(array_intersect($ids1, $ids2))->toBe([]);
});

test('autocomplete zwraca podpowiedzi zaczynające się dopasowaniem prefiksu', function () {
    $suggestions = service()->suggest('smartf');

    expect($suggestions)->not->toBeEmpty();

    foreach ($suggestions as $suggestion) {
        expect(mb_strtolower($suggestion['name']))->toContain('smartf');
    }
});

test('pusta fraza w autocomplete nie odpytuje ES, zwraca pustą listę', function () {
    expect(service()->suggest(''))->toBe([]);
});

test('histogram cen zwraca kubełki z licznikiem', function () {
    $histogram = service()->priceHistogram(criteria(['q' => '']));

    expect($histogram)->not->toBeEmpty()
        ->and(array_sum(array_column($histogram, 'count')))->toBeGreaterThan(0);
});
