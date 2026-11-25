<?php

use App\Security\ClientFingerprint;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

// Log::spy() wymaga kontenera aplikacji, a testy Unit domyślnie go nie mają.
uses(TestCase::class);

beforeEach(fn () => ClientFingerprint::resetWarning());

test('hasz to 16 znaków hex, stały dla tego samego peppera', function () {
    $a = (new ClientFingerprint('pieprz-a'))->hash('203.0.113.7');

    expect($a)->toMatch('/^[0-9a-f]{16}$/')
        ->and((new ClientFingerprint('pieprz-a'))->hash('203.0.113.7'))->toBe($a);
});

test('inny pepper daje inny hasz', function () {
    expect((new ClientFingerprint('pieprz-a'))->hash('203.0.113.7'))
        ->not->toBe((new ClientFingerprint('pieprz-b'))->hash('203.0.113.7'));
});

test('pusty pepper lub niepoprawny IP daje null', function () {
    Log::spy();

    expect((new ClientFingerprint(''))->hash('203.0.113.7'))->toBeNull()
        ->and((new ClientFingerprint(null))->hash('203.0.113.7'))->toBeNull()
        ->and((new ClientFingerprint('pieprz'))->hash('nie-ip'))->toBeNull();
});

test('adres mapowany IPv4 daje ten sam hasz co zwykły IPv4', function () {
    $f = new ClientFingerprint('pieprz');

    expect($f->hash('::ffff:203.0.113.7'))->toBe($f->hash('203.0.113.7'));
});

test('prefiks sieci: /24 dla IPv4, /48 dla IPv6, IPv4 z adresu mapowanego', function () {
    $f = new ClientFingerprint('pieprz');

    expect($f->prefix('203.0.113.7'))->toBe('203.0.113.0/24')
        ->and($f->prefix('2001:db8:1:2::5'))->toBe('2001:db8:1::/48')
        ->and($f->prefix('::ffff:203.0.113.7'))->toBe('203.0.113.0/24')
        ->and($f->prefix('x'))->toBeNull();
});

test('pusty pepper: prefiks też null, oba pola IP idą w parze', function () {
    Log::spy();

    expect((new ClientFingerprint(''))->prefix('203.0.113.7'))->toBeNull()
        ->and((new ClientFingerprint(null))->prefix('2001:db8:1:2::5'))->toBeNull();
});

test('user-agent obcięty do 256 znaków, null zostaje null', function () {
    expect(ClientFingerprint::userAgent(str_repeat('a', 300)))->toHaveLength(256)
        ->and(ClientFingerprint::userAgent('Mozilla'))->toBe('Mozilla')
        ->and(ClientFingerprint::userAgent(null))->toBeNull();
});

test('pusty pepper loguje ostrzeżenie tylko raz na proces', function () {
    Log::spy();
    $f = new ClientFingerprint('');

    $f->hash('203.0.113.7');
    $f->prefix('203.0.113.7');
    $f->hash('203.0.113.8');

    Log::shouldHaveReceived('warning')->once();
});

test('kontener buduje odcisk z config services.security.ip_pepper', function () {
    config(['services.security.ip_pepper' => 'z-configu']);

    expect(app(ClientFingerprint::class)->hash('203.0.113.7'))
        ->toBe((new ClientFingerprint('z-configu'))->hash('203.0.113.7'));
});
