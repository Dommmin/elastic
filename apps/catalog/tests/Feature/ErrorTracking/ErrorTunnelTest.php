<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Tunel błędów z przeglądarki do GlitchTipa (`POST /_errors`, ADR-0009).
 *
 * Przeglądarka nie łączy się z GlitchTipem bezpośrednio. Trasa to NIE otwarty
 * przekaźnik: cel i klucz projektu są w configu, a nie w żądaniu klienta, i
 * obowiązuje ją limit liczby żądań (na IP i globalny).
 */
const ENVELOPE = "{\"event_id\":\"abc\",\"dsn\":\"http://other-key@glitchtip:8000/3\"}\n{\"type\":\"event\"}\n{\"message\":\"boom\"}\n";

beforeEach(function () {
    config([
        'error_tracking.tunnel.key' => 'frontend-key',
        'error_tracking.tunnel.project_id' => '2',
        'error_tracking.tunnel.upstream' => 'glitchtip:8000',
    ]);
    Http::preventStrayRequests();
});

/** Pierwszy pasujący stub wygrywa, więc każdy test deklaruje swój. */
function upstreamResponds(int $status = 200): void
{
    Http::fake(['glitchtip:8000/*' => Http::response('', $status)]);
}

function tunnelPost(array $headers = [], string $body = ENVELOPE, string $uri = '/_errors'): TestResponse
{
    return test()->call('POST', $uri, [], [], [], test()->transformHeadersToServerVars(array_merge([
        'Content-Type' => 'text/plain;charset=UTF-8',
    ], $headers)), $body);
}

test('koperta idzie do projektu z configu, a klucz i nagłówki klienta nie zmieniają celu', function () {
    upstreamResponds();
    tunnelPost([
        'Cookie' => 'laravel_session=secret',
        'Authorization' => 'Bearer secret',
        'X-Sentry-Auth' => 'Sentry sentry_key=other-key, sentry_version=7',
    ], uri: '/_errors?sentry_key=other-key&glitchtip_key=other-key')->assertOk();

    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'http://glitchtip:8000/api/2/envelope/?sentry_key=frontend-key'
        && $request->body() === ENVELOPE
        && ! $request->hasHeader('Cookie')
        && ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('X-Sentry-Auth'));
    Http::assertSentCount(1);
});

test('inna metoda niż POST kończy się 405', function () {
    $this->get('/_errors')->assertStatus(405);
    $this->put('/_errors')->assertStatus(405);

    Http::assertNothingSent();
});

test('pusty klucz wyłącza tunel (204) i niczego nie wysyła', function () {
    config(['error_tracking.tunnel.key' => '']);

    tunnelPost()->assertNoContent();

    Http::assertNothingSent();
});

test('skompresowane ciało jest odrzucane (415)', function () {
    tunnelPost(['Content-Encoding' => 'gzip'])->assertStatus(415);

    Http::assertNothingSent();
});

test('ciało powyżej 1 MB jest odrzucane (413)', function () {
    tunnelPost(body: str_repeat('a', 1024 * 1024 + 1))->assertStatus(413);

    Http::assertNothingSent();
});

test('GlitchTip niedostępny daje 502', function () {
    Http::fake(['glitchtip:8000/*' => fn () => throw new ConnectionException('Could not resolve host')]);

    tunnelPost()->assertStatus(502);
});

test('GlitchTip z błędem serwera daje 502', function () {
    upstreamResponds(500);

    tunnelPost()->assertStatus(502);
});

test('odmowa GlitchTipa (4xx, np. 429 albo zła koperta) jest przekazywana z kodem', function () {
    upstreamResponds(400);

    tunnelPost()->assertStatus(400);
});

test('limit na IP: 21. żądanie w minucie dostaje 429 z Retry-After, inny adres nie', function () {
    upstreamResponds();
    foreach (range(1, 20) as $i) {
        tunnelPost()->assertOk();
    }

    tunnelPost()->assertStatus(429)->assertHeader('Retry-After');

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2']);
    tunnelPost()->assertOk();
});

test('limit globalny domyślnie 60 na minutę (rozmiar bazy GlitchTipa, RUNBOOK #077)', function () {
    expect(config('error_tracking.tunnel.rate_global'))->toBe(60)
        ->and(config('error_tracking.tunnel.rate_per_ip'))->toBe(20);
});

test('limit globalny: po 60 żądaniach w minucie z różnych adresów dostaje 429', function () {
    upstreamResponds();
    foreach (range(1, 60) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.'.intdiv($i, 250).'.'.($i % 250)]);
        tunnelPost()->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9']);
    tunnelPost()->assertStatus(429);
});

test('trasa nie wymaga sesji ani tokenu CSRF', function () {
    upstreamResponds();
    $response = tunnelPost();

    $response->assertOk();
    expect($response->headers->getCookies())->toBeEmpty();
});
