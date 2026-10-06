<?php

namespace App\Messenger;

use App\Message\IntegrationEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
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
 * UWAGA (błąd, który tu był): encode() pierwotnie rzucał wyjątkiem z
 * założeniem "Symfony nigdy nie publikuje do tych transportów". To błędne
 * założenie — `retry_strategy` w messenger.yaml (max_retries: 3) PRZY
 * NIEUDANEJ PRÓBIE PONAWIA wiadomość, wysyłając ją z powrotem na TEN SAM
 * transport, co wymaga encode(). Rzucanie wyjątku tutaj wywalało cały
 * mechanizm retry: SendFailedMessageForRetryListener łapał wyjątek
 * handlera, próbował odesłać wiadomość, encode() rzucał kolejny wyjątek,
 * i wiadomość lądowała w DLQ po PIERWSZEJ próbie zamiast po trzech
 * (z odpowiednim backoffem). Retry to też publikacja — trzeba to wspierać.
 *
 * DRUGA UWAGA (RUNBOOK #027): licznik ponowień Messengera to STAMP
 * (RedeliveryStamp), a stampy przeżywają podróż przez brokera tylko wtedy,
 * gdy serializer je zapisze. Domyślny serializer robi to sam
 * (X-Message-Stamp-*); ten — nie robił. Każdy retry wracał więc jako
 * "próba #1", MultiplierRetryStrategy nigdy nie widziała przekroczonego
 * max_retries i wiadomość krążyła bez końca co ~1 s. Stąd nagłówek
 * RETRY_COUNT_HEADER poniżej — jedyny stan, który musimy nieść.
 */
final class ExternalJsonEnvelopeSerializer implements SerializerInterface
{
    public const RETRY_COUNT_HEADER = 'X-Message-Retry-Count';

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

        $envelope = new Envelope($message);

        // Brak nagłówka = świeża wiadomość z Laravela (licznik 0).
        $retryCount = (int) ($encodedEnvelope['headers'][self::RETRY_COUNT_HEADER] ?? 0);

        return $retryCount > 0 ? $envelope->with(new RedeliveryStamp($retryCount)) : $envelope;
    }

    /**
     * Odwrotność decode() — potrzebna WYŁĄCZNIE do retry (patrz uwaga w
     * docblocku klasy). search-service nadal nigdy nie publikuje NOWYCH
     * zdarzeń tymi transportami (D-02) — to wyłącznie ponowna wysyłka
     * wiadomości, która już przyszła z Laravela, w tym samym formacie.
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        if (! $message instanceof IntegrationEvent) {
            throw new \LogicException(
                'ExternalJsonEnvelopeSerializer umie zakodować tylko IntegrationEvent, otrzymał: '
                .get_debug_type($message),
            );
        }

        $body = json_encode([
            'id' => $message->id,
            'type' => $message->type,
            'version' => $message->version,
            'source' => $message->source,
            'occurred_at' => $message->occurredAt->format(DATE_ATOM),
            'aggregate' => [
                'type' => $message->aggregateType,
                'id' => $message->aggregateId,
            ],
            'sequence' => $message->sequence,
            'data' => $message->data,
        ], JSON_THROW_ON_ERROR);

        $headers = ['content_type' => 'application/json'];

        $retryCount = RedeliveryStamp::getRetryCountFromEnvelope($envelope);
        if ($retryCount > 0) {
            $headers[self::RETRY_COUNT_HEADER] = (string) $retryCount;
        }

        return [
            'body' => $body,
            'headers' => $headers,
        ];
    }
}
