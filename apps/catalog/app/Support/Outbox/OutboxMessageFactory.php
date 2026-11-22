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
        return self::fromEnvelope(self::envelope($row), $row->trace_id);
    }

    /**
     * Wiadomość z gotowej koperty — tej samej drogi używa symulator analityki.
     *
     * @param  array<string, mixed>  $envelope
     */
    public static function fromEnvelope(array $envelope, ?string $traceId = null): AMQPMessage
    {
        $properties = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];

        // Nagłówek tylko dla wierszy ze śladem — stare wiersze (sprzed
        // migracji) lecą bez niego, a konsument to toleruje.
        if ($traceId !== null) {
            $properties['application_headers'] = new AMQPTable(['trace_id' => $traceId]);
        }

        return new AMQPMessage(json_encode($envelope, JSON_THROW_ON_ERROR), $properties);
    }

    /**
     * @return array<string, mixed>
     */
    private static function envelope(Outbox $row): array
    {
        return EventEnvelope::make(
            id: $row->event_id,
            type: $row->event_type,
            occurredAt: $row->occurred_at,
            aggregateType: $row->aggregate_type,
            aggregateId: (string) $row->aggregate_id,
            sequence: (int) $row->sequence,
            data: $row->payload,
            version: (int) ($row->version ?? 1),
        );
    }
}
