<?php

namespace Billing\Infrastructure;

use Billing\Application\Port\Clock;
use DateTimeImmutable;

/**
 * `now()` Laravela, a nie `new DateTimeImmutable`: respektuje `Carbon::setTestNow()`.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return now()->toDateTimeImmutable();
    }
}
