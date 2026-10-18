<?php

use App\Support\Tracing\TraceId;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

// Context to fasada Laravela — potrzebuje bootowanej aplikacji.
uses(TestCase::class);

beforeEach(fn () => Context::flush());

test('wyciąga trace_id z poprawnego nagłówka traceparent', function () {
    expect(TraceId::fromTraceparent('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'))
        ->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

test('odrzuca niepoprawny traceparent', function (?string $header) {
    expect(TraceId::fromTraceparent($header))->toBeNull();
})->with([
    'brak' => [null],
    'pusty' => [''],
    'śmieci' => ['garbage'],
    'wersja ff' => ['ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
    'wielkie litery' => ['00-4BF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-01'],
    'same zera' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'],
    'za krótki trace' => ['00-4bf92f3577b34da6a3ce929d0e0e47-00f067aa0ba902b7-01'],
]);

test('generate daje 32 znaki hex i za każdym razem inny', function () {
    $a = TraceId::generate();

    expect($a)->toMatch('/^[0-9a-f]{32}$/')
        ->and(TraceId::generate())->not->toBe($a);
});

test('current zwraca ten sam id w obrębie jednego Context', function () {
    $first = TraceId::current();

    expect(TraceId::current())->toBe($first)
        ->and(Context::get(TraceId::CONTEXT_KEY))->toBe($first);
});

test('toTraceparent buduje nagłówek, który da się odczytać z powrotem', function () {
    $trace = TraceId::generate();
    $header = TraceId::toTraceparent($trace);

    expect($header)->toMatch('/^00-'.$trace.'-[0-9a-f]{16}-01$/')
        ->and(TraceId::fromTraceparent($header))->toBe($trace);
});
