<?php

namespace App\Tracing;

use App\Messenger\TraceIdStamp;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Pilnuje życia TraceContext wokół jednej wiadomości. Czyszczenie jest
 * konieczne: worker to długo żyjący proces, więc ślad, którego nie wyczyścimy,
 * "wyciekłby" do logów następnej wiadomości (innego żądania, innego śladu).
 *
 * DLACZEGO czyścimy na WorkerRunningEvent, a nie na Handled/Failed: Worker
 * (vendor/symfony/messenger/Worker.php, handleMessage -> ack()) wysyła
 * WorkerMessageHandled/FailedEvent, a dopiero PO ich listenerach loguje
 * "was handled successfully (acknowledging…)" i robi ack/reject; listener
 * SendFailedMessageToFailureTransportListener (priorytet -100) loguje
 * "Rejected message … failure transport" też po kimkolwiek, kto czyściłby na
 * Failed. WorkerRunningEvent leci dopiero po całym handleMessage(), więc
 * wszystkie te logi zachowują trace_id, a ślad i tak znika przed następną
 * wiadomością (onReceived i tak go nadpisuje).
 */
final class TraceContextSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TraceContext $trace) {}

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => 'onReceived',
            WorkerRunningEvent::class => 'onRunning',
        ];
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        // Brak stampa = ustawiamy null, nie zostawiamy śladu poprzedniej wiadomości.
        $this->trace->set($event->getEnvelope()->last(TraceIdStamp::class)?->traceId);
    }

    public function onRunning(WorkerRunningEvent $event): void
    {
        $this->trace->clear();
    }
}
