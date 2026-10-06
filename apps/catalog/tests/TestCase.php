<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
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
