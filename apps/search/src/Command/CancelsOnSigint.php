<?php

namespace App\Command;

/**
 * Ctrl-C w komendzie śledzącej zadanie ES: flaga `$interrupted` zamiast zabicia procesu.
 */
trait CancelsOnSigint
{
    private bool $interrupted = false;

    /**
     * Ctrl-C w trakcie `--wait` nie zabija procesu, tylko ustawia flagę —
     * pętla odpytywania woła wtedy `_cancel` i czeka na zatrzymanie zadania.
     * Własny handler zamiast SignalableCommandInterface, bo ten działa tylko
     * pod Application (CommandTester go pomija); poprzedni handler wraca.
     *
     * @template T
     *
     * @param callable(): T $body
     *
     * @return T
     */
    protected function withSigint(callable $body): mixed
    {
        $this->interrupted = false;
        $previousAsync = pcntl_async_signals(true);
        $previous = pcntl_signal_get_handler(\SIGINT);
        pcntl_signal(\SIGINT, function (): void {
            $this->interrupted = true;
        });

        try {
            return $body();
        } finally {
            pcntl_signal(\SIGINT, $previous);
            pcntl_async_signals($previousAsync);
        }
    }
}
