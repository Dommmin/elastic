<?php

namespace App\Tests\Support;

use Sentry\Event;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/**
 * Transport Sentry SDK, który zamiast wysyłać po HTTP odkłada zdarzenia w pamięci.
 *
 * Statyczny, bo kontener buduje własną instancję (config/packages/sentry.yaml,
 * blok when@test), a test nie ma do niej uchwytu. Zdarzenie trafia tu PO `before_send`,
 * czyli to, co poszłoby do GlitchTipa.
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
