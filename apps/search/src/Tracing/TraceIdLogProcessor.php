<?php

namespace App\Tracing;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Dokleja trace_id do `extra` każdego wpisu logu, gdy jest bieżący ślad —
 * po tym polu łączymy w Kibanie logi catalog i search jednego zdarzenia.
 * Bez śladu klucza nie ma (zamiast pustego pola, które zaśmiecałoby indeks).
 */
#[AsMonologProcessor]
final class TraceIdLogProcessor
{
    public function __construct(private readonly TraceContext $trace) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $traceId = $this->trace->get();
        if ($traceId !== null) {
            $record->extra['trace_id'] = $traceId;
        }

        return $record;
    }
}
