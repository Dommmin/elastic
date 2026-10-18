<?php

namespace App\Support\Outbox;

use App\Models\Outbox;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Buduje wiadomość AMQP z wiersza outboxu. Wydzielone z publishera, żeby
 * kształt koperty i nagłówki dało się testować bez brokera.
 */
final class OutboxMessageFactory
{
    public static function make(Outbox $row): AMQPMessage
    {
        $properties = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];

        // Nagłówek tylko dla wierszy ze śladem — stare wiersze (sprzed
        // migracji) lecą bez niego, a konsument to toleruje.
        if ($row->trace_id !== null) {
            $properties['application_headers'] = new AMQPTable(['trace_id' => $row->trace_id]);
        }

        return new AMQPMessage(json_encode(self::envelope($row), JSON_THROW_ON_ERROR), $properties);
    }

    /**
     * Koperta zdarzenia — dokładnie ten kształt co w docs/05-SPOJNOSC-DANYCH.md
     * (sekcja 3) i docs/02-APLIKACJE.md (sekcja 3). `sequence` to kopia
     * wersji agregatu z momentu zdarzenia — konsument użyje jej jako
     * `version_type=external` przy zapisie do Elasticsearcha.
     *
     * @return array<string, mixed>
     */
    private static function envelope(Outbox $row): array
    {
        return [
            'id' => $row->event_id,
            'type' => $row->event_type,
            'version' => 1,
            'source' => 'catalog',
            'occurred_at' => $row->occurred_at->toIso8601String(),
            'aggregate' => [
                'type' => $row->aggregate_type,
                'id' => $row->aggregate_id,
            ],
            'sequence' => $row->sequence,
            'data' => $row->payload,
        ];
    }
}
