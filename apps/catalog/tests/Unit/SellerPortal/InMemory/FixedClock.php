<?php

namespace Tests\Unit\SellerPortal\InMemory;

use DateTimeImmutable;
use SellerPortal\Application\Port\Clock;

final class FixedClock implements Clock
{
    public function __construct(public DateTimeImmutable $now = new DateTimeImmutable('2026-10-15 12:00:00')) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
