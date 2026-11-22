<?php

namespace App\Tests\Unit\Seller360;

use App\Seller360\Seller360Job;
use App\Seller360\Seller360Scheduler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SchedulerTest extends TestCase
{
    private float $now = 1000.0;

    private function job(string $name, int $interval, int &$runs, ?\Closure $body = null): Seller360Job
    {
        return new class($name, $interval, $runs, $body) implements Seller360Job {
            public function __construct(private string $name, private int $interval, private int &$runs, private ?\Closure $body) {}

            public function name(): string { return $this->name; }

            public function intervalSeconds(): int { return $this->interval; }

            public function run(): void
            {
                $this->runs++;
                if ($this->body !== null) {
                    ($this->body)();
                }
            }
        };
    }

    private function scheduler(array $jobs): Seller360Scheduler
    {
        return new Seller360Scheduler($jobs, new NullLogger, fn (): float => $this->now, function (int $s): void { $this->now += $s; });
    }

    public function test_po_31_s_offers_dwa_razy_a_sales_raz(): void
    {
        $offers = 0;
        $sales = 0;
        $this->scheduler([$this->job('offers', 30, $offers), $this->job('sales', 300, $sales)])
            ->run(fn (): bool => $this->now > 1031.0);

        self::assertSame(2, $offers);
        self::assertSame(1, $sales);
    }

    public function test_wyjatek_zadania_nie_przerywa_petli(): void
    {
        $bad = 0;
        $good = 0;
        $this->scheduler([$this->job('bad', 30, $bad, fn () => throw new \RuntimeException('boom')), $this->job('good', 30, $good)])
            ->run(fn (): bool => $this->now > 1031.0);

        self::assertSame(2, $bad);
        self::assertSame(2, $good);
    }

    public function test_stop_przerywa_przed_kolejnym_zadaniem(): void
    {
        $a = 0;
        $b = 0;
        $stop = false;
        $this->scheduler([$this->job('a', 30, $a, function () use (&$stop) { $stop = true; }), $this->job('b', 30, $b)])
            ->run(function () use (&$stop): bool { return $stop; });

        self::assertSame(1, $a);
        self::assertSame(0, $b);
    }
}
