<?php

use App\Services\ProductSearchService;
use App\Services\Search\SearchCriteria;
use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Http\Request;
use Tests\TestCase;

// Potrzebna aplikacja (config()), ale bez bazy.
uses(TestCase::class);

/**
 * Wspólna część punktująca zapytania (tekst + popularność). Testy nie
 * dotykają ES — budowanie DSL jest bezstanowe.
 */
function scoringService(): ProductSearchService
{
    return new ProductSearchService(ClientBuilder::create()->build());
}

function scoringCriteria(array $query): SearchCriteria
{
    return SearchCriteria::fromRequest(Request::create('/search', 'GET', $query));
}

test('boost 0: zapytanie search() ma kształt sprzed zmiany (snapshot)', function () {
    config(['services.search.popularity_boost' => 0.0]);

    $query = scoringService()->buildSearchRequestQuery(scoringCriteria(['q' => 'buty', 'brand' => ['Acme']]));

    expect($query)->toEqual([
        'bool' => [
            'must' => [[
                'multi_match' => [
                    'query' => 'buty',
                    'type' => 'best_fields',
                    'tie_breaker' => 0.3,
                    'fields' => ['name^3', 'name.ac', 'brand^2', 'description'],
                ],
            ]],
            'filter' => [
                ['terms' => ['brand' => ['Acme']]],
                scoringServiceCheapestOfferClause(),
            ],
        ],
    ]);
});

function scoringServiceCheapestOfferClause(): array
{
    $m = new ReflectionMethod(ProductSearchService::class, 'cheapestOfferClause');

    return $m->invoke(scoringService());
}

test('boost 2 i q=buty: should zawiera rank_feature na popularity', function () {
    config(['services.search.popularity_boost' => 2.0]);

    $scoring = scoringService()->buildScoringQuery('buty');

    expect($scoring['bool']['must'])->toEqual([scoringService()->buildTextQuery('buty')])
        ->and($scoring['bool']['should'])->toEqual([
            ['rank_feature' => ['field' => 'popularity', 'saturation' => new stdClass, 'boost' => 2.0]],
        ]);
});

test('puste q: brak rank_feature nawet przy boost > 0', function () {
    config(['services.search.popularity_boost' => 2.0]);

    $scoring = scoringService()->buildScoringQuery('');

    expect(json_encode($scoring))->not->toContain('rank_feature')
        ->and($scoring)->toHaveKey('match_all');
});

test('boost 0: część punktująca to samo buildTextQuery', function () {
    config(['services.search.popularity_boost' => 0.0]);

    expect(scoringService()->buildScoringQuery('buty'))->toEqual(scoringService()->buildTextQuery('buty'));
});

test('search() i buildSearchQuery() mają tę samą część punktującą', function () {
    config(['services.search.popularity_boost' => 3.0]);
    $criteria = scoringCriteria(['q' => 'smartfon']);

    $fromSearch = scoringService()->buildSearchRequestQuery($criteria)['bool']['must'][0];
    $fromEval = scoringService()->buildSearchQuery($criteria)['bool']['must'][0];

    expect($fromSearch)->toEqual($fromEval)
        ->and(json_encode($fromEval))->toContain('rank_feature');
});

test('facety nie dostają rank_feature (tekst zostaje filtrem)', function () {
    config(['services.search.popularity_boost' => 3.0]);
    $m = new ReflectionMethod(ProductSearchService::class, 'buildFacetAggs');

    $aggs = $m->invoke(scoringService(), scoringCriteria(['q' => 'smartfon']));

    expect(json_encode($aggs))->not->toContain('rank_feature');
});
