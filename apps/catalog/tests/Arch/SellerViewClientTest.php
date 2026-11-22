<?php

use App\Seller360\SellerStatsReader;
use Elastic\Elasticsearch\Client;

uses(Tests\TestCase::class);

/**
 * Klient `es.seller_view` ma konto o minimalnych uprawnieniach (read na seller-360-public).
 * Gdyby ktokolwiek poza SellerStatsReader go dostał, izolacja basic-owa (osobny alias zamiast FLS) traci sens.
 */
test('nazwa es.seller_view występuje tylko w providerze (rejestracja) i w SellerStatsReader (opis)', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    $mentions = [];
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && str_contains(file_get_contents($file->getPathname()), 'es.seller_view')) {
            $mentions[] = str_replace(app_path().'/', '', $file->getPathname());
        }
    }

    sort($mentions);

    expect($mentions)->toBe(['Providers/AppServiceProvider.php', 'Seller360/SellerStatsReader.php']);
});

test('SellerStatsReader używa innego klienta niż reszta aplikacji', function () {
    config(['services.elasticsearch_seller_view' => ['user' => 'catalog_seller_view', 'password' => 'x']]);

    $reader = new ReflectionProperty(SellerStatsReader::class, 'client');
    $statsClient = $reader->getValue(app(SellerStatsReader::class));

    expect($statsClient)->toBeInstanceOf(Client::class)
        ->and($statsClient)->not->toBe(app(Client::class))
        ->and($statsClient)->toBe(app('es.seller_view'));
});

test('żadna inna klasa w app/ nie wstrzykuje klienta seller_view poprzez SellerStatsReader poza kontrolerem dashboardu', function () {
    $users = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && str_contains(file_get_contents($file->getPathname()), 'SellerStatsReader')) {
            $users[] = str_replace(app_path().'/', '', $file->getPathname());
        }
    }
    sort($users);

    expect($users)->toBe(['Http/Controllers/Seller/SellerDashboardController.php', 'Providers/AppServiceProvider.php', 'Seller360/SellerStatsReader.php']);
});
