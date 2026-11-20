<?php

namespace App\Analytics\Simulation;

use App\Support\Outbox\OutboxMessageFactory;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

/**
 * Publikacja zdarzeń symulatora prosto do `marketplace.events` (spec ETAP 12,
 * sekcja 4): przy setkach tysięcy zdarzeń historii outbox byłby wąskim
 * gardłem i zaśmieciłby tabelę. Koperta i wiadomość AMQP z tych samych klas
 * co outbox (`EventEnvelope`, `OutboxMessageFactory`), routing key = typ
 * zdarzenia — od RabbitMQ w górę droga jest identyczna jak z UI.
 *
 * Publisher confirms w paczkach po BATCH: zdarzenie liczy się jako
 * opublikowane dopiero po ack brokera. Połączenie otwierane leniwie, przy
 * pierwszej paczce (test podmienia klasę i nie dotyka brokera).
 */
class SimulatedEventPublisher
{
    public const BATCH = 500;

    private const CONFIRM_TIMEOUT_SECONDS = 30;

    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private int $confirmed = 0;

    private int $rejected = 0;

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function publish(array $envelope): void
    {
        $this->buffer[] = $envelope;

        if (count($this->buffer) >= self::BATCH) {
            $this->flush();
        }
    }

    /**
     * Wysyła resztę bufora, zamyka połączenie i zwraca liczbę potwierdzonych.
     *
     * @throws RuntimeException gdy broker odrzucił lub nie potwierdził części zdarzeń
     */
    public function finish(): int
    {
        $this->flush();

        $this->channel?->close();
        $this->connection?->close();
        $this->channel = null;
        $this->connection = null;

        if ($this->rejected > 0) {
            throw new RuntimeException("Broker nie potwierdził {$this->rejected} zdarzeń (potwierdzone: {$this->confirmed}).");
        }

        return $this->confirmed;
    }

    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $channel = $this->channel();
        $pending = [];

        foreach ($this->buffer as $envelope) {
            $message = OutboxMessageFactory::fromEnvelope($envelope);
            // Referencja trzyma obiekt przy życiu, więc spl_object_id się nie powtórzy.
            $pending[spl_object_id($message)] = $message;
            $channel->basic_publish($message, config('services.rabbitmq.events_exchange'), $envelope['type']);
        }
        $this->buffer = [];

        // Handlery wołane synchronicznie w wait_for_pending_acks(), jak w outbox:publish.
        $channel->set_ack_handler(function (AMQPMessage $msg) use (&$pending): void {
            if (isset($pending[spl_object_id($msg)])) {
                unset($pending[spl_object_id($msg)]);
                $this->confirmed++;
            }
        });
        $channel->set_nack_handler(function (AMQPMessage $msg) use (&$pending): void {
            if (isset($pending[spl_object_id($msg)])) {
                unset($pending[spl_object_id($msg)]);
                $this->rejected++;
            }
        });

        try {
            $channel->wait_for_pending_acks(self::CONFIRM_TIMEOUT_SECONDS);
        } catch (AMQPTimeoutException) {
            // Niepotwierdzone liczymy jako odrzucone — finish() zgłosi błąd.
        }

        $this->rejected += count($pending);
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel === null) {
            $this->connection = new AMQPStreamConnection(
                config('services.rabbitmq.host'),
                config('services.rabbitmq.port'),
                config('services.rabbitmq.user'),
                config('services.rabbitmq.password'),
                config('services.rabbitmq.vhost'),
            );
            $this->channel = $this->connection->channel();
            $this->channel->confirm_select();
        }

        return $this->channel;
    }
}
