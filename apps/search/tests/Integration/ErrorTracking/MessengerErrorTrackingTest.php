<?php

namespace App\Tests\Integration\ErrorTracking;

use App\Messenger\TraceIdStamp;
use App\Tests\Support\FakeSentryTransport;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;

/**
 * Śledzenie błędów w konsumencie Messengera, na PRAWDZIWYM kontenerze:
 * listener bundla Sentry, EnrichEvent i TraceContextSubscriber pochodzą z configu,
 * test tylko odtwarza zdarzenia workera w kolejności, w jakiej wysyła je Worker
 * (received → failed/handled → running).
 *
 * Nazwa odbiorcy `test_receiver` jest celowa: żaden transport o tej nazwie nie ma
 * strategii retry ani failure transportu, więc listenery Symfony nie sięgają do
 * RabbitMQ ani do bazy. To, czy wiadomość pójdzie do retry, ustawia test
 * (`setForRetry()`), dokładnie jak robi to SendFailedMessageForRetryListener.
 */
final class MessengerErrorTrackingTest extends KernelTestCase
{
    private EventDispatcherInterface $dispatcher;

    protected function setUp(): void
    {
        self::bootKernel();
        FakeSentryTransport::reset();
        $this->dispatcher = self::getContainer()->get('event_dispatcher');
    }

    public function test_message_that_will_be_retried_is_not_reported(): void
    {
        $this->process(new Envelope(new \stdClass), fail: new \RuntimeException('przejściowy'), willRetry: true);

        self::assertCount(0, FakeSentryTransport::$events);
    }

    public function test_message_failing_for_good_is_reported_once_with_trace_id_from_stamp(): void
    {
        $traceId = str_repeat('b', 32);

        $this->process(new Envelope(new \stdClass, [new TraceIdStamp($traceId)]), fail: new \RuntimeException('trwały'), willRetry: false);

        self::assertCount(1, FakeSentryTransport::$events);
        $tags = FakeSentryTransport::$events[0]->getTags();
        self::assertSame($traceId, $tags['trace_id']);
        self::assertSame('consumer', $tags['process']);
    }

    public function test_next_message_without_stamp_does_not_inherit_previous_trace_id(): void
    {
        $this->process(new Envelope(new \stdClass, [new TraceIdStamp(str_repeat('b', 32))]), fail: new \RuntimeException('pierwszy'), willRetry: false);
        $this->process(new Envelope(new \stdClass), fail: new \RuntimeException('drugi'), willRetry: false);

        self::assertCount(2, FakeSentryTransport::$events);
        self::assertArrayNotHasKey('trace_id', FakeSentryTransport::$events[1]->getTags());
    }

    public function test_successfully_handled_message_is_not_reported(): void
    {
        $this->process(new Envelope(new \stdClass, [new TraceIdStamp(str_repeat('c', 32))]), fail: null, willRetry: false);

        self::assertCount(0, FakeSentryTransport::$events);
    }

    public function test_client_options_are_privacy_safe_and_bounded(): void
    {
        $options = self::getContainer()->get(HubInterface::class)->getClient()->getOptions();

        self::assertFalse($options->shouldSendDefaultPii());
        self::assertSame('never', $options->getMaxRequestBodySize());
        self::assertSame(0.3, (float) $options->getHttpConnectTimeout());
        self::assertSame(1.0, (float) $options->getHttpTimeout());
        self::assertFalse($options->isTracingEnabled());
        self::assertSame('test', $options->getEnvironment());
    }

    public function test_empty_dsn_disables_the_client(): void
    {
        $previous = $_SERVER['SENTRY_DSN'] ?? null;
        self::ensureKernelShutdown();
        $_SERVER['SENTRY_DSN'] = $_ENV['SENTRY_DSN'] = '';
        putenv('SENTRY_DSN=');

        try {
            self::bootKernel();
            $options = self::getContainer()->get(HubInterface::class)->getClient()->getOptions();

            self::assertNull($options->getDsn());
        } finally {
            $_SERVER['SENTRY_DSN'] = $_ENV['SENTRY_DSN'] = $previous;
            putenv('SENTRY_DSN='.$previous);
            self::ensureKernelShutdown();
        }
    }

    public function test_frame_variables_with_arguments_are_not_sent(): void
    {
        // Obraz FrankenPHP nie ma php.ini, więc `zend.exception_ignore_args` jest tam Off.
        ini_set('zend.exception_ignore_args', '0');

        $this->process(
            new Envelope(new \stdClass),
            fail: self::exceptionThrownWithArguments(['email' => 'jan.kowalski@example.com', 'password' => 'Plaintext#123']),
            willRetry: false,
        );

        $frames = [];
        foreach (FakeSentryTransport::$events[0]->getExceptions() as $exception) {
            foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
                $frames[] = ['function' => $frame->getFunctionName(), 'vars' => $frame->getVars()];
            }
        }

        self::assertStringContainsString('exceptionThrownWithArguments', implode(' ', array_column($frames, 'function')), 'Ramka funkcji z argumentami musi być w śladzie.');
        self::assertStringNotContainsString('Plaintext#123', (string) json_encode($frames));
        self::assertStringNotContainsString('jan.kowalski', (string) json_encode($frames));
    }

    /**
     * @param array<string, string> $input
     */
    private static function exceptionThrownWithArguments(array $input): \Throwable
    {
        try {
            self::throwWithArguments($input);
        } catch (\RuntimeException $e) {
            return $e;
        }
    }

    /**
     * @param array<string, string> $input
     */
    private static function throwWithArguments(array $input): never
    {
        throw new \RuntimeException('z argumentami');
    }

    /**
     * Kolejność zdarzeń jak w Worker::handleMessage(): received, potem failed albo
     * handled, a dopiero po całej obsłudze running (stąd TraceContextSubscriber
     * czyści ślad dopiero na końcu).
     */
    private function process(Envelope $envelope, ?\Throwable $fail, bool $willRetry): void
    {
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'test_receiver'));

        if ($fail === null) {
            $this->dispatcher->dispatch(new WorkerMessageHandledEvent($envelope, 'test_receiver'));
        } else {
            $event = new WorkerMessageFailedEvent($envelope, 'test_receiver', $fail);
            if ($willRetry) {
                $event->setForRetry();
            }
            $this->dispatcher->dispatch($event);
        }

        $this->dispatcher->dispatch(new WorkerRunningEvent(new Worker([], new MessageBus, $this->dispatcher), false));
    }
}
