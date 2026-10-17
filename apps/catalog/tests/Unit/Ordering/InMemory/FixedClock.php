<?php

namespace Tests\Unit\Ordering\InMemory;

use DateInterval;
use DateTimeImmutable;
use Ordering\Application\Port\Clock;

final class FixedClock implements Clock
{
    public function __construct(public DateTimeImmutable $now = new DateTimeImmutable('2026-10-16 12:00:00')) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Przesuwa zamrożony czas o interwał ISO 8601 (np. `PT20M`), jak `travel` w testach HTTP.
     */
    public function advance(string $interval): void
    {
        $this->now = $this->now->add(new DateInterval($interval));
    }
}
