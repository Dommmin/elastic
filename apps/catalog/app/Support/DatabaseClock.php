<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Zegar bazy danych w mikrosekundach — źródło wersji dokumentu ES
 * (`projected_at_us`). Zegar Postgresa (a nie PHP) daje jedną oś czasu
 * dla wszystkich replik aplikacji, niezależną od dryfu zegarów kontenerów.
 * `clock_timestamp()` (nie `now()`): `now()` stoi w miejscu przez całą
 * transakcję, a tu potrzebujemy chwili faktycznego wywołania.
 *
 * Bez stanu, więc bezpieczne pod Octane.
 */
class DatabaseClock
{
    /**
     * Aktualny czas w mikrosekundach od epoki Uniksa.
     */
    public function nowMicros(): int
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return (int) DB::scalar('select (extract(epoch from clock_timestamp()) * 1000000)::bigint');
        }

        // SQLite w testach nie ma odpowiednika clock_timestamp().
        return (int) (microtime(true) * 1_000_000);
    }
}
