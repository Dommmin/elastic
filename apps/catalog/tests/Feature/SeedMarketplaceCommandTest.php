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
