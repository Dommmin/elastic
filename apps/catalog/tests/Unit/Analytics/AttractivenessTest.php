<?php

use App\Analytics\Simulation\Attractiveness;

test('ta sama para (ziarno, ID) daje tę samą atrakcyjność', function () {
    expect(Attractiveness::for(123, 7))->toBe(Attractiveness::for(123, 7))
        ->and(Attractiveness::for(123, 7))->not->toBe(Attractiveness::for(123, 8))
        ->and(Attractiveness::for(123, 7))->not->toBe(Attractiveness::for(124, 7));
});

test('wartości leżą w (0,1), a średnia dla 10k ID to ≈ 2/7 jak w Beta(2,5)', function () {
    $sum = 0.0;
    $min = 1.0;
    $max = 0.0;
    foreach (range(1, 10_000) as $id) {
        $a = Attractiveness::for($id, 1);
        $sum += $a;
        $min = min($min, $a);
        $max = max($max, $a);
    }

    expect($sum / 10_000)->toEqualWithDelta(2 / 7, 0.02)
        ->and($min)->toBeGreaterThan(0.0)
        ->and($max)->toBeLessThan(1.0);
});

test('wariancja zgadza się z Beta(2,5) (10/392 ≈ 0.0255)', function () {
    $values = array_map(fn (int $id) => Attractiveness::for($id, 3), range(1, 10_000));
    $mean = array_sum($values) / count($values);
    $variance = array_sum(array_map(fn (float $v) => ($v - $mean) ** 2, $values)) / count($values);

    expect($variance)->toEqualWithDelta(10 / 392, 0.003);
});
