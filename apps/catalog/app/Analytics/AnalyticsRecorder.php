<?php

namespace App\Analytics;

use App\Models\Outbox;
use App\Support\Tracing\TraceId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Zapis zdarzeń `user.*` przez istniejący outbox (ETAP 12, spec sekcja 4).
 *
 * Wiersz idzie POZA transakcją domenową i nigdy nie psuje żądania: analityka
 * może zginąć, strona ma działać. Publikuje go `outbox-publisher`, routing key
 * to `event_type`.
 */
class AnalyticsRecorder
{
    /**
     * `$version` to wersja schematu ładunku w kopercie (2 = atrybucja do sprzedawcy, ETAP 13).
     *
     * @param  array<string, mixed>  $data
     */
    public function record(string $type, string $sessionId, array $data, ?CarbonImmutable $occurredAt = null, int $version = 1): void
    {
        try {
            Outbox::create([
                'event_id' => (string) Str::ulid(),
                'aggregate_type' => 'session',
                'aggregate_id' => $sessionId,
                'event_type' => $type,
                'payload' => $data,
                'sequence' => 0,
                'occurred_at' => $occurredAt ?? now(),
                'trace_id' => TraceId::current(),
                'version' => $version,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
