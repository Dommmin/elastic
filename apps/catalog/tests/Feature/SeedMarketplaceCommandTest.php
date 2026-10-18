<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seed emituje zdarzenia outboxu, a droga zdarzenia w panelu porównuje
 * `occurred_at` w µs z `_version` dokumentu w ES. Zdarzenie zapisane z
 * dokładnością do sekundy zapalałoby krok „w ES” za wcześnie (albo — przy
 * zaokrągleniu w górę — nigdy), więc sprawdzamy SUROWĄ wartość w bazie,
 * nie rzutowanie modelu.
 */
test('seed zapisuje occurred_at zdarzeń z mikrosekundami', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-11 12:00:00.123456'));

    $this->artisan('marketplace:seed', ['--n' => 2, '--no-publish' => true])->assertSuccessful();

    $raw = DB::table('outbox')->pluck('occurred_at');

    expect($raw)->toHaveCount(2)
        ->and($raw->all())->each->toContain('12:00:00.123456');
});

test('seed zapisuje zdarzenia z jednym wspólnym trace_id', function () {
    $this->artisan('marketplace:seed', ['--n' => 3, '--no-publish' => true])->assertSuccessful();

    $traceIds = DB::table('outbox')->pluck('trace_id')->unique();

    expect(DB::table('outbox')->count())->toBe(3)
        ->and($traceIds)->toHaveCount(1)
        ->and($traceIds->first())->toMatch('/^[0-9a-f]{32}$/');
});
