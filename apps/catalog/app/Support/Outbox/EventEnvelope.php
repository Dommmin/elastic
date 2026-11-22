<?php

namespace App\Support\Outbox;

use DateTimeInterface;

/**
 * Koperta zdarzenia integracyjnego — dokładnie ten kształt co w
 * docs/05-SPOJNOSC-DANYCH.md (sekcja 3) i docs/02-APLIKACJE.md (sekcja 3).
 * Jedno źródło kształtu dla outboxu (`OutboxMessageFactory`) i symulatora
 * analityki (`analytics:simulate`), który publikuje z pominięciem outboxu.
 *
 * `sequence` to kopia wersji agregatu z momentu zdarzenia — konsument użyje
 * jej jako `version_type=external` przy zapisie do Elasticsearcha.
 */
final class EventEnvelope
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function make(
        string $id,
        string $type,
        DateTimeInterface $occurredAt,
        string $aggregateType,
        string $aggregateId,
        int $sequence,
        array $data,
        int $version = 1,
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'version' => $version,
            'source' => 'catalog',
            'occurred_at' => $occurredAt->format(DateTimeInterface::ATOM),
            'aggregate' => [
                'type' => $aggregateType,
                'id' => $aggregateId,
            ],
            'sequence' => $sequence,
            'data' => $data,
        ];
    }
}
