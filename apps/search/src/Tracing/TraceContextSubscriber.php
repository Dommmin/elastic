<?php

namespace App\Tracing;

use App\Messenger\TraceIdStamp;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Pilnuje życia TraceContext wokół jednej wiadomości. Czyszczenie po
 * sukcesie ORAZ po porażce jest konieczne: worker to długo żyjący proces,
 * więc ślad, którego nie wyczyścimy, "wyciekłby" do logów następnej
 * wiadomości (innego żądania, innego śladu).
 */
final class TraceContextSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TraceContext $trace) {}

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => 'onReceived',
            WorkerMessageHandledEvent::class => 'onFinished',
            WorkerMessageFailedEvent::class => 'onFinished',
        ];
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        // Brak stampa = ustawiamy null, nie zostawiamy śladu poprzedniej wiadomości.
        $this->trace->set($event->getEnvelope()->last(TraceIdStamp::class)?->traceId);
    }

    public function onFinished(WorkerMessageHandledEvent|WorkerMessageFailedEvent $event): void
    {
        $this->trace->clear();
    }
}
