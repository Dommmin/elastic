<?php

namespace App\Messenger;

use App\Message\IntegrationEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Serializer transportu AMQP dla zdarzeń publikowanych PRZEZ LARAVEL, nie
 * przez Symfony Messenger.
 *
 * DLACZEGO to jest potrzebne: domyślny serializer Messengera
 * (`Symfony\Component\Messenger\Transport\Serialization\Serializer`,
 * oparty na komponencie Serializer) oczekuje własnego formatu koperty
 * z nagłówkami typu `X-Message-Stamp-*`, które dodaje SAM Messenger przy
 * publikacji. Nasze wiadomości to zwykły, płaski JSON wysyłany
 * `php-amqplib`-em z app/Console/Commands/PublishOutboxCommand.php
 * (docs/05-SPOJNOSC-DANYCH.md, sekcja 3) — zupełnie inny kształt.
 *
 * Ten serializer tłumaczy jeden format na drugi: JSON z RabbitMQ ->
 * obiekt IntegrationEvent owinięty w Envelope, który dopiero wewnątrz
 * Symfony trafia do właściwego handlera (patrz `fromTransport` w
 * atrybutach `#[AsMessageHandler]`).
 *
 * encode() celowo rzuca wyjątkiem: Symfony w tym systemie NIGDY nie
 * publikuje do tych transportów, tylko konsumuje — to jednokierunkowa
 * integracja (docs/02-APLIKACJE.md, D-02).
 */
final class ExternalJsonEnvelopeSerializer implements SerializerInterface
{
    public function decode(array $encodedEnvelope): Envelope
    {
        $body = $encodedEnvelope['body'] ?? '';

        try {
            $payload = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // MessageDecodingFailedException jest UnrecoverableExceptionInterface —
            // Messenger nie ponawia, tylko od razu wysyła do failure_transport.
            // Ponawianie wiadomości z niepoprawnym JSON-em nic by nie dało
            // (to nie jest problem przejściowy jak timeout sieci).
            throw new MessageDecodingFailedException('Nieprawidłowy JSON w wiadomości AMQP: '.$e->getMessage(), previous: $e);
        }

        foreach (['id', 'type', 'version', 'source', 'occurred_at', 'aggregate', 'sequence', 'data'] as $requiredField) {
            if (! array_key_exists($requiredField, $payload)) {
                throw new MessageDecodingFailedException("Brak wymaganego pola '{$requiredField}' w kopercie zdarzenia.");
            }
        }

        $message = new IntegrationEvent(
            id: $payload['id'],
            type: $payload['type'],
            version: (int) $payload['version'],
            source: $payload['source'],
            occurredAt: new \DateTimeImmutable($payload['occurred_at']),
            aggregateType: $payload['aggregate']['type'],
            aggregateId: (string) $payload['aggregate']['id'],
            sequence: (int) $payload['sequence'],
            data: $payload['data'],
        );

        return new Envelope($message);
    }

    public function encode(Envelope $envelope): array
    {
        throw new \LogicException(
            'search-service tylko konsumuje te transporty, nigdy nie publikuje '.
            '(docs/02-APLIKACJE.md, D-02) — jeśli tu trafiłeś, prawdopodobnie '.
            'próbujesz $bus->dispatch() zamiast oczekiwać na wiadomość z RabbitMQ.',
        );
    }
}
