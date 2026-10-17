<?php

namespace Ordering\Infrastructure;

use DateTimeImmutable;
use Ordering\Application\Port\Clock;

/**
 * DLACZEGO `now()` Laravela, a nie `new DateTimeImmutable`: tylko ono
 * respektuje `Carbon::setTestNow()`, więc testy (i sweep pod `travelTo`)
 * mogą zamrozić czas.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return now()->toDateTimeImmutable();
    }
}
