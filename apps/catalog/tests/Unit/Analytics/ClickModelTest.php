<?php

use App\Analytics\Simulation\ClickModel;

function flatClickModel(float $a = 0.3): ClickModel
{
    return new ClickModel(fn (int $productId): float => $a);
}

test('przy eta=1 i stałej atrakcyjności CTR pozycji 1 to ≈ 2× CTR pozycji 2', function () {
    $model = flatClickModel();
    $results = range(1, 20);
    $perPosition = array_fill(1, 20, 0);

    foreach (range(1, 10_000) as $trial) {
        foreach ($model->clicks($results, $trial, 1.0, 0.0) as $position) {
            $perPosition[$position]++;
        }
    }

    expect($perPosition[1] / $perPosition[2])->toEqualWithDelta(2.0, 0.2)
        ->and($perPosition[1] / 10_000)->toEqualWithDelta(0.3, 0.02)
        ->and($perPosition[1] / $perPosition[4])->toEqualWithDelta(4.0, 0.6);
});

test('to samo ziarno daje te same kliknięcia', function () {
    $model = new ClickModel(fn (int $id): float => ($id % 10) / 10 + 0.05);
    $results = range(100, 119);

    foreach (range(1, 50) as $seed) {
        expect($model->clicks($results, $seed, 1.0, 0.05))->toBe($model->clicks($results, $seed, 1.0, 0.05));
    }
});

test('kliknięcia to rosnące pozycje 1-based z zakresu wyników', function () {
    $model = flatClickModel(0.9);

    foreach (range(1, 200) as $seed) {
        $clicks = $model->clicks([5, 6, 7], $seed, 0.5, 0.0);
        $sorted = $clicks;
        sort($sorted);
        expect($clicks)->toBe($sorted)->and(array_unique($clicks))->toBe($clicks);
        foreach ($clicks as $p) {
            expect($p)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3);
        }
    }

    expect($model->clicks([], 1, 1.0, 0.0))->toBe([]);
});

test('szum=1 klika pozycje jednostajnie, bez biasu pozycji', function () {
    $model = flatClickModel();
    $results = range(1, 10);
    $perPosition = array_fill(1, 10, 0);

    foreach (range(1, 10_000) as $trial) {
        foreach ($model->clicks($results, $trial, 1.0, 1.0) as $position) {
            $perPosition[$position]++;
        }
    }

    expect($perPosition[1] / $perPosition[10])->toEqualWithDelta(1.0, 0.15);
});

test('mnożnik 0.2 (ctr-drop) obcina CTR pięciokrotnie', function () {
    $model = flatClickModel(0.5);
    $normal = 0;
    $dropped = 0;

    foreach (range(1, 10_000) as $trial) {
        $normal += count($model->clicks([1, 2, 3], $trial, 1.0, 0.0));
        $dropped += count($model->clicks([1, 2, 3], $trial, 1.0, 0.0, 0.2));
    }

    expect($normal / $dropped)->toEqualWithDelta(5.0, 0.5);
});
