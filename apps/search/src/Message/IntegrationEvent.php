<?php

namespace App\Message;

/**
 * Odpowiednik koperty zdarzenia z docs/05-SPOJNOSC-DANYCH.md (sekcja 3) po
 * stronie Symfony. Jeden typ wiadomości dla WSZYSTKICH trzech transportów
 * (product_sync, analytics, alerts) — to, który handler ją odbierze,
 * decyduje `#[AsMessageHandler(fromTransport: '...')]` w handlerze, nie typ
 * wiadomości. Dzięki temu jeden serializer (ExternalJsonEnvelopeSerializer)
 * obsługuje cały ruch z RabbitMQ.
 */
final readonly class IntegrationEvent
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $id,
        public string $type,
        public int $version,
        public string $source,
        public \DateTimeImmutable $occurredAt,
        public string $aggregateType,
        public string $aggregateId,
        public int $sequence,
        public array $data,
    ) {
    }
}
