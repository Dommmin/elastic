<?php

use Illuminate\Support\Facades\Route;

/**
 * ETAP G2: na k3s ruch użytkowników przychodzi przez Ingress (Traefik), który kończy
 * TLS i przekazuje dalej zwykłe HTTP z nagłówkami X-Forwarded-*. Bez zaufania do
 * proxy Laravel widzi http:// i generuje linki/przekierowania na http — przeglądarka
 * na https://shop.marketplace.test:8443 dostaje mieszaną treść i pętlę logowania.
 *
 * Komu ufać, mówi TRUSTED_PROXIES (na k3s: sieć podów 10.42.0.0/16 — do catalog-app:80
 * dochodzi tylko Traefik, pilnuje tego NetworkPolicy). Puste = nikomu (Compose, testy):
 * wtedy klient nie może podszyć się nagłówkiem X-Forwarded-For pod inny adres IP
 * (limit żądań w ErrorTunnelController liczy po IP).
 */
function bootWithTrustedProxies(?string $value): void
{
    $value === null ? putenv('TRUSTED_PROXIES') : putenv("TRUSTED_PROXIES={$value}");
    test()->refreshApplication();
    Route::get('/_test/url', fn () => response()->json([
        'url' => url('/panel'),
        'secure' => request()->isSecure(),
        'ip' => request()->ip(),
    ]));
}

afterEach(fn () => putenv('TRUSTED_PROXIES'));

$forwarded = [
    'X-Forwarded-Proto' => 'https',
    'X-Forwarded-Host' => 'shop.marketplace.test',
    'X-Forwarded-Port' => '8443',
    'X-Forwarded-For' => '198.51.100.7',
];

test('za zaufanym proxy (Traefik w sieci podów) linki są https z hostem i portem z Ingressu', function () use ($forwarded) {
    bootWithTrustedProxies('10.42.0.0/16');

    $this->withServerVariables(['REMOTE_ADDR' => '10.42.0.15'])
        ->withHeaders($forwarded)
        ->getJson('/_test/url')
        ->assertOk()
        ->assertJson([
            'url' => 'https://shop.marketplace.test:8443/panel',
            'secure' => true,
            'ip' => '198.51.100.7',
        ]);
});

test('nagłówki X-Forwarded-* od adresu spoza TRUSTED_PROXIES są ignorowane', function () use ($forwarded) {
    bootWithTrustedProxies('10.42.0.0/16');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders($forwarded)
        ->getJson('/_test/url')
        ->assertOk()
        ->assertJson(['secure' => false, 'ip' => '203.0.113.9']);
});

test('bez TRUSTED_PROXIES nikt nie jest zaufany (Compose, dev)', function () use ($forwarded) {
    bootWithTrustedProxies(null);

    $this->withServerVariables(['REMOTE_ADDR' => '10.42.0.15'])
        ->withHeaders($forwarded)
        ->getJson('/_test/url')
        ->assertOk()
        ->assertJson(['secure' => false, 'ip' => '10.42.0.15']);
});
