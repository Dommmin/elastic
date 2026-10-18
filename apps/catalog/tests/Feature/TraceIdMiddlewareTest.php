<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

test('każda odpowiedź ma nagłówek X-Trace-Id', function () {
    $this->get('/up')->assertHeader('X-Trace-Id');

    expect($this->get('/up')->headers->get('X-Trace-Id'))->toMatch('/^[0-9a-f]{32}$/');
});

test('poprawny traceparent jest przejmowany', function () {
    $this->get('/up', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])
        ->assertHeader('X-Trace-Id', '4bf92f3577b34da6a3ce929d0e0e4736');
});

test('niepoprawny traceparent zastępuje nowy id', function () {
    $response = $this->get('/up', ['traceparent' => 'garbage']);

    expect($response->headers->get('X-Trace-Id'))
        ->toMatch('/^[0-9a-f]{32}$/')
        ->not->toBe('garbage');
});

test('trace_id jest w Context w trakcie obsługi żądania', function () {
    Route::get('/__trace-probe', fn () => response(Context::get('trace_id')));

    $response = $this->get('/__trace-probe');

    expect($response->getContent())->toBe($response->headers->get('X-Trace-Id'));
});
