<?php

namespace App\Tests\Integration\Messenger;

use App\Message\IntegrationEvent;
use App\MessageHandler\ProductSyncHandler;
use App\Messenger\AckAfterHandOffTransport;
use App\Messenger\ExternalJsonEnvelopeSerializer;
use App\Repository\ProcessedEventRepository;
use App\Service\CatalogProjectionClient;
use App\Service\ElasticsearchIndexer;
use Elastic\Elasticsearch\ClientBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\RejectRedeliveredMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Yaml\Yaml;

/**
 * RUNBOOK #027 — retry przez PRAWDZIWEGO RabbitMQ, nie przez InMemoryTransport.
 *
 * Bug, który ten test łapie, w ogóle nie istnieje bez brokera: retry z
 * opóźnieniem to publikacja na exchange opóźnień (`delay.exchange_name`)
 * + dynamiczna kolejka z TTL, która po czasie oddaje wiadomość przez DLX
 * z powrotem do kolejki źródłowej. Brak tego exchange'a w topologii
 * (`auto_setup: false`!) = 404 NOT_FOUND przy PIERWSZYM retry.
 *
 * Test NIE dotyka kolejki search.product.sync (konsumuje ją żywy
 * search-consumer — wiadomość testowa mogłaby trafić do niego). Zamiast tego:
 *  - deklaruje jednorazową kolejkę quorum z DLX jak w definitions.json,
 *  - bierze opcje `delay` i `retry_strategy` z messenger.yaml transportu
 *    product_sync (testujemy KONFIGURACJĘ, nie kopię wartości w teście),
 *  - uruchamia prawdziwy ProductSyncHandler z CatalogProjectionClient
 *    celującym w zamknięty port — ten sam ProjectionUnavailableException
 *    co przy "Idle timeout" z incydentu.
 *
 * Wymaga: ext-amqp + MESSENGER_TRANSPORT_DSN do żywego brokera (kontener
 * search-consumer ma oba). Inaczej test jest pomijany, nie zielony.
 */
#[Group('integration')]
final class ProductSyncRetryTest extends TestCase
{
    private const TRANSPORT = 'product_sync';

    private \AMQPChannel $channel;
    private string $queue;
    private string $deadLetterQueue;

    protected function setUp(): void
    {
        if (! \extension_loaded('amqp')) {
            self::markTestSkipped('Brak ext-amqp — uruchom w kontenerze search-consumer.');
        }

        $dsn = $_SERVER['MESSENGER_TRANSPORT_DSN'] ?? getenv('MESSENGER_TRANSPORT_DSN') ?: '';
        if (! str_starts_with($dsn, 'amqp://') || str_contains($dsn, '@127.0.0.1')) {
            self::markTestSkipped('MESSENGER_TRANSPORT_DSN nie wskazuje brokera z sieci dockera.');
        }

        $connection = new \AMQPConnection($this->connectionParams());
        $connection->connect();
        $this->channel = new \AMQPChannel($connection);

        $suffix = bin2hex(random_bytes(4));
        $this->queue = "test.retry.{$suffix}";
        $this->deadLetterQueue = "test.retry.{$suffix}.dlq";

        $this->declareQueue($this->deadLetterQueue, ['x-queue-type' => 'quorum']);
        // Te same argumenty co search.product.sync w definitions.template.json,
        // z DLX przez default exchange ('') prosto do naszej DLQ.
        $this->declareQueue($this->queue, [
            'x-queue-type' => 'quorum',
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => $this->deadLetterQueue,
            'x-delivery-limit' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->channel)) {
            foreach ([$this->queue, $this->deadLetterQueue] as $name) {
                $queue = new \AMQPQueue($this->channel);
                $queue->setName($name);
                $queue->delete();
            }
            $this->channel->getConnection()->disconnect();
        }
    }

    public function test_transient_failure_is_retried_three_times_with_backoff_then_goes_to_failure_transport(): void
    {
        $this->publishLikeLaravelOutbox($this->event());

        [$attempts, $failed] = $this->runWorkerUntilMessageReachesFailureTransport();

        $retry = $this->transportConfig()['retry_strategy'];

        // 1 próba + max_retries ponowień — nie 1 (brak exchange'a opóźnień),
        // nie "do deadline'u" (retry bez limitu).
        self::assertCount(1 + $retry['max_retries'], $attempts, 'liczba wywołań handlera');

        // Backoff: 1000 ms, ×multiplier, ±10% jitter (domyślny w Symfony 8)
        // + trochę luzu na polling workera i TTL kolejki opóźnień.
        for ($i = 1; $i < \count($attempts); ++$i) {
            $expectedMs = ($retry['delay'] ?? 1000) * $retry['multiplier'] ** ($i - 1);
            $gapMs = ($attempts[$i] - $attempts[$i - 1]) * 1000;
            self::assertGreaterThanOrEqual($expectedMs * 0.9, $gapMs, "odstęp przed retry #{$i}");
            self::assertLessThan($expectedMs * 1.1 + 1000, $gapMs, "odstęp przed retry #{$i}");
        }

        self::assertCount(1, $failed, 'dopiero po wyczerpaniu prób -> failure_transport');
        self::assertSame('01TESTRETRY0000000000000000', $failed[0]->getMessage()->id);

        // Po wszystkim w brokerze nie zostaje nic: przejęte przez retry /
        // failure_transport próby dostają ack, nie nack -> DLX -> DLQ.
        self::assertSame(0, $this->messageCount($this->queue), 'kolejka źródłowa');
        self::assertSame(0, $this->messageCount($this->deadLetterQueue), 'DLQ');
    }

    public function test_message_redelivered_after_consumer_crash_lands_in_dlq_and_is_still_retried(): void
    {
        $this->publishLikeLaravelOutbox($this->event());
        $this->simulateConsumerCrashMidHandling();

        [$attempts, $failed] = $this->runWorkerUntilMessageReachesFailureTransport();

        // Redelivery: Worker robi reject PRZED listenerami — nikt jeszcze nie
        // przejął wiadomości, więc to ma być prawdziwy nack -> DLQ (1 kopia),
        // a Messenger i tak puszcza ją w normalny cykl retry.
        self::assertSame(1, $this->messageCount($this->deadLetterQueue), 'DLQ');
        self::assertCount(1, $failed);
        self::assertCount($this->transportConfig()['retry_strategy']['max_retries'], $attempts,
            'pierwsza "próba" to odrzucona redelivery, bez wywołania handlera');
    }

    /**
     * Ta sama konfiguracja, co kontener dla transportu product_sync:
     * opcje z messenger.yaml, ExternalJsonEnvelopeSerializer,
     * AckAfterHandOffTransport (services.yaml), retry + failure listenery.
     * Bez doctrine_transaction — repozytorium jest tu stubem; rollback
     * znacznika sprawdza ręczny repro w RUNBOOK #027.
     *
     * @return array{0: list<float>, 1: list<Envelope>} [czasy wywołań handlera, failure_transport]
     */
    private function runWorkerUntilMessageReachesFailureTransport(): array
    {
        $config = $this->transportConfig();
        $retry = $config['retry_strategy'];

        $transport = new AckAfterHandOffTransport(new AmqpTransport(
            Connection::fromDsn($_SERVER['MESSENGER_TRANSPORT_DSN'] ?? getenv('MESSENGER_TRANSPORT_DSN'), [
                'auto_setup' => $config['options']['auto_setup'],
                'delay' => $config['options']['delay'] ?? [],
                'queues' => [$this->queue => []],
            ]),
            new ExternalJsonEnvelopeSerializer(),
        ));
        $failureTransport = new InMemoryTransport();

        $attempts = [];
        $handler = $this->productSyncHandlerFailingOnProjectionFetch();
        // Kolejność jak w domyślnym stosie busa frameworka:
        // reject_redelivered_message_middleware przed handle_message.
        $bus = new MessageBus([new RejectRedeliveredMessageMiddleware(), new HandleMessageMiddleware(new HandlersLocator([
            IntegrationEvent::class => [static function (IntegrationEvent $event) use (&$attempts, $handler): void {
                $attempts[] = microtime(true);
                $handler($event);
            }],
        ]))]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            new ServiceLocator([self::TRANSPORT => static fn () => $transport]),
            new ServiceLocator([self::TRANSPORT => static fn () => new MultiplierRetryStrategy(
                $retry['max_retries'],
                $retry['delay'] ?? 1000,
                $retry['multiplier'],
            )]),
        ));
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(
            new ServiceLocator([self::TRANSPORT => static fn () => $failureTransport]),
        ));

        $deadline = microtime(true) + 20;
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $e) use ($failureTransport, $deadline): void {
            if ($failureTransport->getSent() !== [] || microtime(true) > $deadline) {
                $e->getWorker()->stop();
            }
        });

        (new Worker([self::TRANSPORT => $transport], $bus, $dispatcher))->run(['sleep' => 50_000]);

        // nack -> DLX w quorum jest asynchroniczny; daj brokerowi chwilę,
        // zanim test policzy wiadomości w DLQ.
        usleep(300_000);

        return [$attempts, $failureTransport->getSent()];
    }

    /** basic.get bez ack + zerwane połączenie = to, co widzi broker przy OOM/kill konsumenta. */
    private function simulateConsumerCrashMidHandling(): void
    {
        $connection = new \AMQPConnection($this->connectionParams());
        $connection->connect();
        $queue = new \AMQPQueue(new \AMQPChannel($connection));
        $queue->setName($this->queue);
        self::assertNotFalse($queue->get(\AMQP_NOPARAM), 'wiadomość testowa w kolejce');
        $connection->disconnect();
    }

    /** @return array<string, mixed> */
    private function connectionParams(): array
    {
        $parts = parse_url($_SERVER['MESSENGER_TRANSPORT_DSN'] ?? getenv('MESSENGER_TRANSPORT_DSN'));

        return [
            'host' => $parts['host'],
            'port' => $parts['port'] ?? 5672,
            'login' => urldecode($parts['user']),
            'password' => urldecode($parts['pass']),
            'vhost' => urldecode(ltrim($parts['path'] ?? '/', '/')) ?: '/',
        ];
    }

    /**
     * @return array{options: array<string, mixed>, retry_strategy: array<string, mixed>}
     */
    private function transportConfig(): array
    {
        $yaml = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/messenger.yaml');

        return $yaml['framework']['messenger']['transports'][self::TRANSPORT];
    }

    private function productSyncHandlerFailingOnProjectionFetch(): ProductSyncHandler
    {
        $processedEvents = $this->createStub(ProcessedEventRepository::class);
        $processedEvents->method('tryMarkProcessed')->willReturn(true);

        return new ProductSyncHandler(
            $processedEvents,
            // Port 9 (discard) na localhost: natychmiastowe "connection
            // refused" -> TransportException -> ProjectionUnavailableException,
            // dokładnie ta gałąź co "Idle timeout" przy make seed.
            new CatalogProjectionClient('http://127.0.0.1:9'),
            new ElasticsearchIndexer(ClientBuilder::create()->build()),
            new NullLogger(),
        );
    }

    private function event(): IntegrationEvent
    {
        return new IntegrationEvent(
            id: '01TESTRETRY0000000000000000',
            type: 'product.updated',
            version: 1,
            source: 'catalog',
            occurredAt: new \DateTimeImmutable(),
            aggregateType: 'product',
            aggregateId: '457',
            sequence: 1,
            data: [],
        );
    }

    /** Tak jak PublishOutboxCommand w Laravelu: goły JSON, bez nagłówków Symfony. */
    private function publishLikeLaravelOutbox(IntegrationEvent $event): void
    {
        $encoded = (new ExternalJsonEnvelopeSerializer())->encode(new Envelope($event));

        $exchange = new \AMQPExchange($this->channel);
        $exchange->publish($encoded['body'], $this->queue, \AMQP_NOPARAM, [
            'content_type' => 'application/json',
            'delivery_mode' => 2,
        ]);
    }

    /** @param array<string, mixed> $arguments */
    private function declareQueue(string $name, array $arguments): void
    {
        $queue = new \AMQPQueue($this->channel);
        $queue->setName($name);
        $queue->setFlags(\AMQP_DURABLE);
        $queue->setArguments($arguments);
        $queue->declareQueue();
    }

    private function messageCount(string $name): int
    {
        $queue = new \AMQPQueue($this->channel);
        $queue->setName($name);
        $queue->setFlags(\AMQP_PASSIVE);

        return $queue->declareQueue();
    }
}
