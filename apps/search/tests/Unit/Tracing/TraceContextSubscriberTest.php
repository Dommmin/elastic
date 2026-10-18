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
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Worker;

final class TraceContextSubscriberTest extends TestCase
{
    private const TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';

    private function envelope(): Envelope
    {
        return new Envelope(new \stdClass, [new TraceIdStamp(self::TRACE)]);
    }

    public function test_slad_zyje_przez_listenery_handled_i_failed_a_znika_po_worker_running(): void
    {
        $trace = new TraceContext;
        $subscriber = new TraceContextSubscriber($trace);

        $subscriber->onReceived(new WorkerMessageReceivedEvent($this->envelope(), 'product_sync'));
        $this->assertSame(self::TRACE, $trace->get());

        // Subskrybent nie nasłuchuje Handled/Failed: log "acknowledging" i
        // "Rejected message … failure transport" muszą jeszcze widzieć ślad.
        $events = array_keys(TraceContextSubscriber::getSubscribedEvents());
        $this->assertNotContains(WorkerMessageHandledEvent::class, $events);
        $this->assertNotContains(WorkerMessageFailedEvent::class, $events);
        $this->assertSame(self::TRACE, $trace->get());

        $subscriber->onRunning(new WorkerRunningEvent($this->createStub(Worker::class), false));
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
