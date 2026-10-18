<?php

namespace App\Tests\Unit\Tracing;

use App\Messenger\TraceIdStamp;
use App\Tracing\TraceContext;
use App\Tracing\TraceContextSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

final class TraceContextSubscriberTest extends TestCase
{
    private const TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';

    private function envelope(): Envelope
    {
        return new Envelope(new \stdClass, [new TraceIdStamp(self::TRACE)]);
    }

    public function test_received_ustawia_a_handled_czysci(): void
    {
        $trace = new TraceContext;
        $subscriber = new TraceContextSubscriber($trace);

        $subscriber->onReceived(new WorkerMessageReceivedEvent($this->envelope(), 'product_sync'));
        $this->assertSame(self::TRACE, $trace->get());

        $subscriber->onFinished(new WorkerMessageHandledEvent($this->envelope(), 'product_sync'));
        $this->assertNull($trace->get());
    }

    public function test_failed_czysci(): void
    {
        $trace = new TraceContext;
        $subscriber = new TraceContextSubscriber($trace);

        $subscriber->onReceived(new WorkerMessageReceivedEvent($this->envelope(), 'product_sync'));
        $subscriber->onFinished(new WorkerMessageFailedEvent($this->envelope(), 'product_sync', new \RuntimeException));
        $this->assertNull($trace->get());
    }

    public function test_received_bez_stampa_zeruje_poprzedni_slad(): void
    {
        $trace = new TraceContext;
        $trace->set(self::TRACE);
        (new TraceContextSubscriber($trace))->onReceived(
            new WorkerMessageReceivedEvent(new Envelope(new \stdClass), 'product_sync'),
        );

        $this->assertNull($trace->get());
    }
}
