<?php

use App\Support\RaceTest\RaceOutcome;

test('percentyl metodą najbliższej rangi', function () {
    $sorted = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0];

    expect(RaceOutcome::percentile($sorted, 50))->toBe(5.0)
        ->and(RaceOutcome::percentile($sorted, 95))->toBe(10.0)
        ->and(RaceOutcome::percentile([42.0], 95))->toBe(42.0)
        ->and(RaceOutcome::percentile([], 50))->toBe(0.0);
});

test('oczekiwane opłacone to mniejsze z kupujących i stanu', function () {
    expect(RaceOutcome::expectedPaid(50, 10))->toBe(10)
        ->and(RaceOutcome::expectedPaid(3, 10))->toBe(3);
});

test('dokładnie stan opłaconych to sukces bez oversellingu', function () {
    $outcome = new RaceOutcome(buyers: 50, stock: 10, paid: 10);

    expect($outcome->overselling())->toBe(0)
        ->and($outcome->failure())->toBeNull();
});

test('więcej opłaconych niż stan to overselling', function () {
    $outcome = new RaceOutcome(buyers: 50, stock: 10, paid: 12);

    expect($outcome->overselling())->toBe(2)
        ->and($outcome->failure())->toContain('OVERSELLING');
});

test('mniej opłaconych niż oczekiwane to porażka bez oversellingu', function () {
    $outcome = new RaceOutcome(buyers: 50, stock: 10, paid: 9);

    expect($outcome->overselling())->toBe(-1)
        ->and($outcome->failure())->toContain('oczekiwane 10');
});
