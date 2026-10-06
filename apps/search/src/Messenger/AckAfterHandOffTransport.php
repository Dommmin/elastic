<?php

namespace App\Messenger;

use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpReceivedStamp;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\CloseableTransportInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\QueueReceiverInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Dekorator transportów AMQP: "reject" po przekazaniu wiadomości dalej
 * zamienia na ack, żeby DLQ na poziomie kolejki dostawała TYLKO trucizny.
 *
 * DLACZEGO (RUNBOOK #027): Worker Messengera po KAŻDEJ nieudanej próbie
 * najpierw oddaje wiadomość listenerom (retry -> nowa kopia przez exchange
 * opóźnień, albo -> failure_transport), a potem robi $receiver->reject()
 * na oryginale. W AMQP reject = nack bez requeue, a nasze kolejki mają
 * x-dead-letter-exchange (definitions.json) — więc każda próba dokładała
 * kopię do *.dlq, mimo że Messenger już tę wiadomość przejął. Wiadomość,
 * która raz padła na timeout i za drugim razem przeszła, zostawiała w DLQ
 * fałszywy alarm.
 *
 * Kiedy reject() MA zostać prawdziwym nackiem (-> DLX -> DLQ):
 *  - wiadomość redelivered: konsument padł w trakcie obsługi (OOM, kill,
 *    wyjątek z listenera). Worker robi wtedy reject PRZED listenerami
 *    (RejectRedeliveredMessageException), czyli zanim cokolwiek przejęło
 *    wiadomość — DLQ to jedyna siatka bezpieczeństwa.
 * Błędy dekodowania (zły JSON z Laravela) nie przechodzą tędy wcale —
 * AmqpReceiver nackuje je sam, wewnętrznie, więc nadal lądują w DLQ.
 *
 * Gdy publikacja retry / zapis do failure_transport się nie uda, listener
 * rzuca wyjątek i Worker w ogóle nie dochodzi do reject() — wiadomość
 * zostaje bez ack, wraca jako redelivered i trafia w gałąź wyżej.
 */
final class AckAfterHandOffTransport implements QueueReceiverInterface, TransportInterface, SetupableTransportInterface, CloseableTransportInterface, MessageCountAwareInterface
{
    public function __construct(
        private readonly AmqpTransport $inner,
    ) {
    }

    public function reject(Envelope $envelope): void
    {
        $stamp = $envelope->last(AmqpReceivedStamp::class);

        if ($stamp instanceof AmqpReceivedStamp && ! $stamp->getAmqpEnvelope()->isRedelivery()) {
            $this->inner->ack($envelope);

            return;
        }

        $this->inner->reject($envelope);
    }

    public function get(): iterable
    {
        return $this->inner->get();
    }

    public function getFromQueues(array $queueNames): iterable
    {
        return $this->inner->getFromQueues($queueNames);
    }

    public function ack(Envelope $envelope): void
    {
        $this->inner->ack($envelope);
    }

    public function send(Envelope $envelope): Envelope
    {
        return $this->inner->send($envelope);
    }

    public function setup(): void
    {
        $this->inner->setup();
    }

    public function getMessageCount(): int
    {
        return $this->inner->getMessageCount();
    }

    public function close(): void
    {
        $this->inner->close();
    }
}
