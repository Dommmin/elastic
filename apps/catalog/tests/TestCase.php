<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Tests\Support\FakeSentryTransport;

abstract class TestCase extends BaseTestCase
{
    /**
     * DSN włączany tylko przez testy śledzenia błędów (`beforeAll`), reszta
     * testów działa jak produkcja bez DSN. Sentry buduje listenery i middleware
     * przy starcie aplikacji, więc DSN musi być w configu przed bootem
     * providerów; stąd haczyk w createApplication(), a nie `config()` w teście.
     */
    public static ?string $sentryDsn = null;

    /**
     * Wersja z bazowego TestCase (bez śledzenia WithCachedConfig/Routes, z których
     * nie korzystamy), z jedną różnicą: gdy testy włączyły DSN, config dostaje
     * go razem z transportem w pamięci. Żaden test nie wyśle więc ruchu na zewnątrz.
     */
    public function createApplication()
    {
        if (self::$sentryDsn === null) {
            return parent::createApplication();
        }

        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['config']->set('sentry.dsn', self::$sentryDsn);
            $app['config']->set('sentry.transport', new FakeSentryTransport);
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Bezpiecznik przed RefreshDatabase: setUpTraits() odpala migrate:fresh,
     * więc sprawdzamy środowisko ZANIM to nastąpi. Gdy zmienne kontenera
     * przebiją phpunit.xml (RUNBOOK 026), test ma paść głośno, a nie wyczyścić
     * deweloperskiego Postgresa.
     */
    protected function setUpTraits(): array
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if (! $this->app->environment('testing') || $connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException(sprintf(
                'Testy odmawiają startu: env=%s, db=%s/%s (oczekiwano testing, sqlite/:memory:). Sprawdź <server> w phpunit.xml.',
                $this->app->environment(), $connection, $database,
            ));
        }

        return parent::setUpTraits();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
