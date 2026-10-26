<?php

namespace App\Support\ErrorTracking;

use App\Support\Tracing\TraceId;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;
use Throwable;

/**
 * `before_send` Sentry SDK: dokłada do każdego zdarzenia to, po co GlitchTip
 * jest w tym systemie.
 *
 * - `trace_id`: most do Kibany (issue → `trace.id` → pełny przebieg w logach).
 *   Context Laravela przechodzi do zadań kolejki, więc tag mają też workery.
 * - `process`: który proces zgłosił błąd (http, queue, outbox-publisher, scheduler).
 * - `user.id` bez e-maila, nazwy i IP. Użytkownika ustawiamy ZAWSZE od nowa
 *   (także pustego), bo scope Sentry'ego żyje dłużej niż żądanie, a w Octane
 *   zostawiłby poprzedniego.
 *
 * Metoda statyczna, bo callable z configu musi przeżyć `config:cache`.
 * Błąd tutaj nie może zepsuć raportowania błędu, więc przy wyjątku
 * wysyłamy zdarzenie bez wzbogacenia.
 */
final class EnrichEvent
{
    public static function handle(Event $event, ?EventHint $hint = null): ?Event
    {
        try {
            $event->setTag('trace_id', TraceId::current());
            $event->setTag('process', (string) config('error_tracking.process'));

            $userId = auth()->id();

            $event->setUser($userId === null
                ? new UserDataBag
                : UserDataBag::createFromUserIdentifier((string) $userId));
        } catch (Throwable) {
            // Zdarzenie bez tagów jest lepsze niż brak zdarzenia.
        }

        return $event;
    }
}
