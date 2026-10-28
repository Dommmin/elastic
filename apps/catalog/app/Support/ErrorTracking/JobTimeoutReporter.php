<?php

namespace App\Support\ErrorTracking;

use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\TimeoutExceededException;
use Sentry\SentrySdk;

/**
 * Zgłasza zadanie zabite przez limit czasu.
 *
 * Worker obsługuje timeout w handlerze SIGALRM: oznacza zadanie jako nieudane, wysyła
 * `JobTimedOut` i od razu kończy proces. Wyjątek nie przechodzi przez handler wyjątków
 * aplikacji, a SDK wysyła zdarzenia przy zamykaniu procesu, do którego już nie dojdzie.
 * Dlatego raportujemy tu i spłukujemy transport sami, zanim worker wywoła `kill`.
 */
final class JobTimeoutReporter
{
    public function handle(JobTimedOut $event): void
    {
        $hub = SentrySdk::getCurrentHub();
        $hub->captureException(TimeoutExceededException::forJob($event->job));
        $hub->getClient()?->flush(2);
    }
}
