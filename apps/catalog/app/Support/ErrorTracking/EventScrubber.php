<?php

namespace App\Support\ErrorTracking;

use Sentry\Event;

/**
 * Czyści z zdarzenia to, czego natywny filtr SDK (`data_collection` w
 * config/sentry.php) nie obejmuje: tokeny w ŚCIEŻCE adresu i dane z komunikatów
 * błędów SQL. Reszta (zmienne ramek, query string, nagłówki, ciasteczka, body)
 * jest wyłączona w configu, nie tutaj.
 */
final class EventScrubber
{
    /**
     * Linki Fortify z sekretem w ścieżce: reset hasła (`/reset-password/{token}`)
     * i weryfikacja e-maila (`/email/verify/{id}/{hash}`). Zostaje prefiks, żeby
     * issue wskazywało stronę, a nie jej token.
     */
    private const TOKEN_PATHS = '#(/(?:reset-password|email/verify)/)[^?\\#]*#';

    public static function scrub(Event $event): void
    {
        $request = $event->getRequest();

        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = (string) preg_replace(self::TOKEN_PATHS, '$1[Filtered]', $request['url']);
            $event->setRequest($request);
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::scrubSqlMessage($exception->getValue()));
        }
    }

    /**
     * `QueryException` wstawia do komunikatu zapytanie z powiązanymi wartościami
     * (`(Connection: …, SQL: insert … 'jan@x')`), a Postgres dopisuje linię
     * `DETAIL: Key (email)=(jan@x) already exists.`. Zostaje SQLSTATE, opis i nazwa
     * ograniczenia, czyli to, po czym grupuje się issue.
     */
    public static function scrubSqlMessage(string $message): string
    {
        if (! str_contains($message, 'SQLSTATE[')) {
            return $message;
        }

        $message = (string) preg_replace('/\s*\(Connection:.*\)\s*$/s', '', $message);

        return rtrim((string) preg_replace('/\s*DETAIL:.*$/s', '', $message));
    }
}
