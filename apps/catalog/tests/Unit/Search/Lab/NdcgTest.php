<?php

use App\Services\Search\Lab\Ndcg;

/**
 * nDCG@k zgodne z `_rank_eval` (`dcg`, `normalize: true`): zysk `2^ocena - 1`,
 * dyskonto `log2(pozycja + 1)`, ideał z WSZYSTKICH ocen zapytania.
 */
test('idealna kolejność daje 1.0', function () {
    expect(Ndcg::atK(['a', 'b', 'c'], ['a' => 3, 'b' => 2, 'c' => 1], 10))->toBe(1.0);
});

test('odwrócona kolejność dwóch dokumentów', function () {
    expect(Ndcg::atK(['a', 'b'], ['b' => 3, 'a' => 0], 10))->toEqualWithDelta(0.6309297536, 1e-9);
});

test('dokument bez oceny liczy się jak 0', function () {
    expect(Ndcg::atK(['x', 'a'], ['a' => 3], 10))->toEqualWithDelta(0.6309297536, 1e-9);
});

test('oceniony dokument spoza wyników obniża wynik', function () {
    expect(Ndcg::atK(['a'], ['a' => 3, 'b' => 3], 10))->toBeLessThan(1.0);
});

test('k obcina listę wyników', function () {
    expect(Ndcg::atK(['x', 'a'], ['a' => 3], 1))->toBe(0.0);
});

test('brak ocen większych od zera daje 0.0', function () {
    expect(Ndcg::atK(['a'], [], 10))->toBe(0.0)
        ->and(Ndcg::atK(['a'], ['a' => 0], 10))->toBe(0.0);
});
