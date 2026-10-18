<?php

namespace App\Metrics;

/**
 * Liczy gauge'e, które nie dały się policzyć w BIEŻĄCYM scrape'ie.
 *
 * Rejestrowany jako `scoped` (nie `singleton`): pod Octane/FrankenPHP workerem
 * proces żyje przez wiele żądań, a scoped instancje są czyszczone po każdym
 * żądaniu, więc licznik nie przecieka między scrape'ami. `pull()` dodatkowo
 * zeruje stan przy odczycie (gauge błędów jest rejestrowany OSTATNI, więc czyta
 * po wszystkich pozostałych), co chroni też przy braku resetu scoped (testy,
 * kolejne żądania w tym samym kontenerze).
 */
final class ScrapeErrors
{
    private int $count = 0;

    public function record(): void
    {
        $this->count++;
    }

    public function pull(): int
    {
        $count = $this->count;
        $this->count = 0;

        return $count;
    }
}
