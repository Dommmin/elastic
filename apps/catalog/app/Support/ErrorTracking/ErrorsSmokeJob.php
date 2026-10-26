<?php

namespace App\Support\ErrorTracking;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Zadanie, które zawsze rzuca (`errors:smoke --queue`). `tries` = 1, więc od
 * razu jest ostatnią próbą i musi trafić do GlitchTipa (QueueRetryFilter).
 */
final class ErrorsSmokeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $marker) {}

    public function handle(): void
    {
        throw new RuntimeException("errors-smoke:queue:{$this->marker}");
    }
}
