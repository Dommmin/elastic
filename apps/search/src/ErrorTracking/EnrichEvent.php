<?php

namespace App\ErrorTracking;

use App\Tracing\TraceContext;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `before_send` Sentry SDK: dokłada do zdarzenia to, po co GlitchTip jest w tym
 * systemie.
 *
 * - `trace_id`: most do Kibany (issue → `trace.id` → pełny przebieg w logach obu
 *   aplikacji). Bierzemy go z TraceContext, który TraceContextSubscriber wypełnia
 *   ze stampa wiadomości i czyści dopiero po całej jej obsłudze, więc listener
 *   Sentry'ego (WorkerMessageFailedEvent) jeszcze go widzi. Poza wiadomością
 *   (komenda, HTTP) tagu po prostu nie ma.
 * - `process`: który proces zgłosił błąd (consumer, metrics, cli).
 *
 * Błąd tutaj nie może zepsuć raportowania błędu, więc przy wyjątku wysyłamy
 * zdarzenie bez wzbogacenia.
 */
final class EnrichEvent
{
    public function __construct(
        private readonly TraceContext $trace,
        #[Autowire('%env(ERROR_TRACKING_PROCESS)%')]
        private readonly string $process,
    ) {}

    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        try {
            $traceId = $this->trace->get();
            if ($traceId !== null) {
                $event->setTag('trace_id', $traceId);
            }

            $event->setTag('process', $this->process);
        } catch (\Throwable) {
            // Zdarzenie bez tagów jest lepsze niż brak zdarzenia.
        }

        return $event;
    }
}
