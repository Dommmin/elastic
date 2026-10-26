<?php

namespace Tests\Support;

use Sentry\Event;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/**
 * Transport Sentry SDK, który zamiast wysyłać po HTTP odkłada zdarzenia w pamięci.
 *
 * Zdarzenia są statyczne, bo test nie ma uchwytu do instancji zbudowanej
 * przez sentry-laravel przy starcie aplikacji. Test woła `reset()` w `beforeEach`.
 * Do transportu trafia zdarzenie PO `before_send`, czyli to, co poszłoby do GlitchTipa.
 */
final class FakeSentryTransport implements TransportInterface
{
    /** @var list<Event> */
    public static array $events = [];

    public static function reset(): void
    {
        self::$events = [];
    }

    public function send(Event $event): Result
    {
        self::$events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }
}
