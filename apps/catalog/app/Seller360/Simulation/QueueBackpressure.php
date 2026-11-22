<?php

namespace App\Seller360\Simulation;

use Closure;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use Throwable;

/**
 * Hamulec generatora: czeka, aż kolejka konsumenta zejdzie poniżej limitu.
 *
 * DLACZEGO: kolejki `search.seller360` i `search.analytics.ingest` mają politykę
 * `max-length 100000, overflow drop-head` (infra/rabbitmq/definitions.json). Generator na 20 000
 * sprzedawców produkuje ~140 tys. zdarzeń outboksu i ~120 tys. zdarzeń sprzedaży szybciej, niż
 * konsumenci je zjadają (~170/s projektora), więc bez hamulca najstarsze wiadomości lecą do DLQ
 * (pierwszy przebieg na żywo zgubił tak ~19 tys. + ~19 tys.). Głębokość kolejki czytamy pasywnym
 * `queue_declare` (nie konsumuje wiadomości).
 *
 * Zegar i usypianie są wstrzykiwane (test bez prawdziwego czekania). Gdy kolejka nie schodzi
 * poniżej limitu przez `maxWaitSeconds`, leci `QueueBackpressureTimeout` — konsument stoi i dalsze
 * czekanie nic nie da.
 */
class QueueBackpressure
{
    public const LIMIT = 50_000;

    public const DEFAULT_MAX_WAIT_SECONDS = 900;

    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    /** @var Closure(int): void */
    private Closure $sleep;

    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param  (Closure(int): void)|null  $sleep
     * @param  (Closure(): float)|null  $clock
     */
    public function __construct(?Closure $sleep = null, ?Closure $clock = null, private readonly int $pollSeconds = 2)
    {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * @param  (Closure(): int)|null  $pendingUpstream  wiadomości jeszcze przed kolejką (np. nieopublikowany outbox)
     * @param  (Closure(string): void)|null  $onWait  komunikat dla operatora
     * @return float sekundy czekania
     *
     * @throws QueueBackpressureFailed brak kolejki albo połączenia
     * @throws QueueBackpressureTimeout kolejka nie zeszła poniżej limitu w `maxWaitSeconds`
     */
    public function waitBelow(
        string $queue,
        int $limit = self::LIMIT,
        ?Closure $pendingUpstream = null,
        ?Closure $onWait = null,
        int $maxWaitSeconds = self::DEFAULT_MAX_WAIT_SECONDS,
    ): float {
        $started = ($this->clock)();
        $reported = false;

        while (($depth = $this->measure($queue) + ($pendingUpstream === null ? 0 : $pendingUpstream())) >= $limit) {
            $waited = ($this->clock)() - $started;
            if ($waited >= $maxWaitSeconds) {
                throw new QueueBackpressureTimeout(sprintf(
                    'Kolejka %s nie zeszła poniżej %d wiadomości przez %d s (teraz %d). Sprawdź konsumenta.',
                    $queue, $limit, $maxWaitSeconds, $depth,
                ));
            }
            if (! $reported && $onWait !== null) {
                $onWait("  czekam na kolejkę {$queue}: {$depth} wiadomości (limit {$limit})");
                $reported = true;
            }
            ($this->sleep)($this->pollSeconds);
        }

        return ($this->clock)() - $started;
    }

    public function close(): void
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (Throwable) {
            // Kanał mógł już zamknąć broker (np. po 404) — zamykamy, co się da.
        } finally {
            $this->channel = null;
            $this->connection = null;
        }
    }

    private function measure(string $queue): int
    {
        try {
            return $this->depth($queue);
        } catch (QueueBackpressureFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->close();

            throw new QueueBackpressureFailed("Nie mogę odczytać głębokości kolejki {$queue}: {$e->getMessage()}", previous: $e);
        }
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

        try {
            [, $messages] = $this->channel->queue_declare($queue, passive: true);
        } catch (AMQPProtocolChannelException $e) {
            // Broker zamyka kanał po 404 — kolejny odczyt otworzy nowy.
            $this->close();
            if ($e->getCode() === 404) {
                throw new QueueBackpressureFailed("Kolejka {$queue} nie istnieje w RabbitMQ (404). Uruchom stos aplikacji (make up), żeby konsumenci ją zadeklarowali.", previous: $e);
            }

            throw $e;
        }

        return (int) $messages;
    }
}
