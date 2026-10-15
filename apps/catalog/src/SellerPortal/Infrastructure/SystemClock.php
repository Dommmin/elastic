<?php

namespace SellerPortal\Infrastructure;

use DateTimeImmutable;
use SellerPortal\Application\Port\Clock;

/**
 * DLACZEGO `now()` Laravela, a nie `new DateTimeImmutable`: tylko ono
 * respektuje `Carbon::setTestNow()`, więc testy integracyjne mogą zamrozić czas.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return now()->toDateTimeImmutable();
    }
}
