<?php

namespace App\Seller360\Simulation;

use Closure;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Hamulec generatora: czeka, aż kolejka konsumenta zejdzie poniżej limitu.
 *
 * DLACZEGO: kolejki `search.seller360` i `search.analytics.ingest` mają politykę
 * `max-length 100000, overflow drop-head` (infra/rabbitmq/definitions.json). Generator na 20 000
 * sprzedawców produkuje ~140 tys. zdarzeń outboksu i ~120 tys. zdarzeń sprzedaży szybciej, niż
 * konsumenci je zjadają (~170/s projektora), więc bez hamulca najstarsze wiadomości lecą do DLQ
 * (pierwszy przebieg na żywo zgubił tak ~19 tys. + ~19 tys.). Głębokość kolejki czytamy pasywnym
 * `queue_declare` (nie konsumuje wiadomości).
 */
class QueueBackpressure
{
    public const LIMIT = 50_000;

    private const POLL_SECONDS = 2;

    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    /**
     * @param  Closure(): int|null  $pendingUpstream  wiadomości jeszcze przed kolejką (np. nieopublikowany outbox)
     * @param  Closure(string): void|null  $onWait  komunikat dla operatora
     * @return float sekundy czekania
     */
    public function waitBelow(string $queue, int $limit = self::LIMIT, ?Closure $pendingUpstream = null, ?Closure $onWait = null): float
    {
        $started = microtime(true);
        $reported = false;

        while (($depth = $this->depth($queue) + ($pendingUpstream === null ? 0 : $pendingUpstream())) >= $limit) {
            if (! $reported && $onWait !== null) {
                $onWait("  czekam na kolejkę {$queue}: {$depth} wiadomości (limit {$limit})");
                $reported = true;
            }
            sleep(self::POLL_SECONDS);
        }

        return microtime(true) - $started;
    }

    public function close(): void
    {
        $this->channel?->close();
        $this->connection?->close();
        $this->channel = null;
        $this->connection = null;
    }

    protected function depth(string $queue): int
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
        }

        [, $messages] = $this->channel->queue_declare($queue, passive: true);

        return (int) $messages;
    }
}
