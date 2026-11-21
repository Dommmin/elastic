<?php

use App\Http\Middleware\AnalyticsSession;
use App\Models\User;
use Illuminate\Support\Str;

test('pierwsze żądanie ustawia ciasteczko mp_sid z UUID-em na 30 minut', function () {
    $response = $this->get('/');

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'mp_sid');

    expect($cookie)->not->toBeNull()
        ->and(Str::isUuid($cookie->getValue()))->toBeTrue()
        ->and($cookie->getExpiresTime())->toBeGreaterThan(time() + 29 * 60)
        ->and($cookie->getExpiresTime())->toBeLessThanOrEqual(time() + 30 * 60);
});

test('kolejne żądanie z ciasteczkiem zachowuje wartość i przedłuża je', function () {
    $sid = (string) Str::uuid();

    $response = $this->withUnencryptedCookie('mp_sid', $sid)->get('/');

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'mp_sid');
    expect($cookie->getValue())->toBe($sid);
});

test('zepsute ciasteczko zastępuje nowy UUID', function () {
    $response = $this->withUnencryptedCookie('mp_sid', 'nie-uuid')->get('/');

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'mp_sid');
    expect(Str::isUuid($cookie->getValue()))->toBeTrue();
});

test('logowanie nie zmienia mp_sid', function () {
    $sid = (string) Str::uuid();
    $user = User::factory()->create();

    $response = $this->withUnencryptedCookie('mp_sid', $sid)
        ->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticated();
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'mp_sid');
    expect($cookie->getValue())->toBe($sid);
});

test('currentId czyta atrybut requestu', function () {
    $request = request();
    $request->attributes->set(AnalyticsSession::ATTRIBUTE, 'abc');

    expect(AnalyticsSession::currentId($request))->toBe('abc');
});

test('API też dostaje mp_sid', function () {
    $response = $this->getJson('/api/health');

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'mp_sid');
    expect($cookie)->not->toBeNull();
});
