<?php

namespace Ordering\Application\Order;

/**
 * Wynik przebiegu sweepu: ile zamówień wygasło i których nie dało się ruszyć.
 *
 * DLACZEGO błędy w wyniku, a nie w logu: handler jest w warstwie aplikacji
 * i nie zna loggera frameworka. Komenda artisan, która go woła, decyduje,
 * co z błędami zrobić (log, kod wyjścia).
 */
final readonly class ExpireOverdueOrdersResult
{
    /**
     * @param  list<ExpiryFailure>  $failures
     */
    public function __construct(
        public int $expired,
        public array $failures,
    ) {}
}
