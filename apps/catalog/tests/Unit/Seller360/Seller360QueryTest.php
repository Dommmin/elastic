<?php

use App\Seller360\Seller360Query;

test('dodPreset daje bool query z czterema filtrami i sortem po sales.gmv_30d_cents', function () {
    $body = Seller360Query::fromFilters(Seller360Query::dodPreset());

    expect(Seller360Query::dodPreset())->toBe([
        'region' => 'mazowieckie',
        'min_drop' => 0.2,
        'overdue' => true,
        'complaint_phrase' => 'uszkodzon*',
        'sort' => 'gmv',
    ]);

    $filter = $body['query']['bool']['filter'];
    expect($filter)->toHaveCount(4)
        ->and($filter)->toContain(['term' => ['region' => 'mazowieckie']])
        ->and($filter)->toContain(['range' => ['sales.conversion_drop' => ['gte' => 0.2]]])
        ->and($filter)->toContain(['range' => ['billing.overdue_cents' => ['gt' => 0]]])
        ->and($filter)->toContain(['simple_query_string' => [
            'query' => 'uszkodzon*',
            'fields' => ['complaints.recent_texts'],
            'analyze_wildcard' => true,
            'default_operator' => 'and',
        ]])
        ->and($body['query']['bool'])->not->toHaveKey('must');

    // Brak sekcji sales nie może wywrócić sortowania: missing na koniec, unmapped_type dla pustego indeksu.
    expect($body['sort'][0])->toBe(['sales.gmv_30d_cents' => [
        'order' => 'desc',
        'missing' => '_last',
        'unmapped_type' => 'long',
    ]]);
});

test('bez filtrów: match_all, sort stabilny po seller_id', function () {
    $body = Seller360Query::fromFilters([]);

    expect($body['query'])->toEqual(['match_all' => (object) []])
        ->and($body['sort'])->toBe([['seller_id' => ['order' => 'asc']]])
        ->and($body['track_total_hits'])->toBeTrue()
        ->and($body['from'])->toBe(0);
});

test('q szuka po nazwie, NIP, mieście i treści reklamacji', function () {
    $body = Seller360Query::fromFilters(['q' => 'Kasztan']);

    expect($body['query']['bool']['must'])->toBe([[
        'multi_match' => [
            'query' => 'Kasztan',
            'fields' => ['profile.name', 'nip', 'profile.city', 'complaints.recent_texts'],
            'lenient' => true,
        ],
    ]])->and($body['sort'][0])->toBe('_score');
});

test('puste i nieprawidłowe filtry są pomijane', function () {
    $body = Seller360Query::fromFilters([
        'q' => '  ', 'region' => '', 'vat_status' => null, 'overdue' => '0',
        'min_drop' => 'abc', 'complaint_phrase' => '', 'courier' => '', 'sort' => 'cokolwiek',
    ]);

    expect($body['query'])->toEqual(['match_all' => (object) []]);
});

test('status VAT, kurier i strona', function () {
    $body = Seller360Query::fromFilters(['vat_status' => 'inactive', 'courier' => 'courier-3'], page: 3, size: 10);

    expect($body['query']['bool']['filter'])->toBe([
        ['term' => ['registry.vat_status' => 'inactive']],
        ['term' => ['registry.courier.id' => 'courier-3']],
    ])->and($body['from'])->toBe(20)->and($body['size'])->toBe(10);
});
