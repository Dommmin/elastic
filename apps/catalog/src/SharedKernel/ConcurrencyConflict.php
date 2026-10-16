<?php

namespace SharedKernel;

/**
 * Ktoś inny zapisał ten sam agregat między odczytem a zapisem
 * (optymistyczna blokada po `version`). Wygrywa pierwszy zapis, drugi
 * dostaje ten wyjątek zamiast cichego nadpisania cudzej zmiany.
 */
final class ConcurrencyConflict extends DomainException
{
    public const string CODE = 'concurrency_conflict';

    public function __construct(string $message = 'Agregat został zmieniony w międzyczasie.')
    {
        parent::__construct($message);
    }
}
