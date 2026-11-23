<?php

use App\Services\Search\Lab\Rrf;

/**
 * Reciprocal Rank Fusion liczone w PHP (wariant `rrf-client`): suma
 * `1 / (rank_constant + pozycja)` po listach, pozycje od 1.
 */
test('dwie listy łączą się po sumie odwrotności pozycji', function () {
    // a: 1/61 + 1/62, c: 1/63 + 1/61, b: 1/62, d: 1/63
    expect(Rrf::fuse([['a', 'b', 'c'], ['c', 'a', 'd']]))->toBe(['a', 'c', 'b', 'd']);
});

test('wyniki z sumami jak w ES', function () {
    $scores = Rrf::scores([['a', 'b', 'c'], ['c', 'a', 'd']]);

    expect($scores['a'])->toEqualWithDelta(1 / 61 + 1 / 62, 1e-12)
        ->and($scores['d'])->toEqualWithDelta(1 / 63, 1e-12);
});

test('dokument poza oknem się nie liczy', function () {
    // okno 2: `c` z pierwszej listy (pozycja 3) odpada, zostaje tylko z drugiej
    $scores = Rrf::scores([['a', 'b', 'c'], ['c', 'a', 'd']], 60, 2);

    expect($scores)->not->toHaveKey('d')
        ->and($scores['c'])->toEqualWithDelta(1 / 61, 1e-12)
        ->and(Rrf::fuse([['a', 'b', 'c'], ['c', 'a', 'd']], 60, 2))->toBe(['a', 'c', 'b']);
});

test('remis rozstrzyga mniejsze id jako string', function () {
    // `20` i `100` mają po 1/61; jako string "100" < "20"
    expect(Rrf::fuse([['20'], ['100']]))->toBe(['100', '20']);
});

test('pusta lista daje pusty wynik', function () {
    expect(Rrf::fuse([]))->toBe([])
        ->and(Rrf::fuse([[], []]))->toBe([]);
});
