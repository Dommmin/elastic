<?php

namespace App\Seller360;

use Psr\Log\LoggerInterface;

/**
 * Pętla harmonogramu seller-360: co sekundę uruchamia zadania, którym minął interwał.
 *
 * Pierwsze uruchomienie każdego zadania następuje od razu przy starcie. Wyjątek zadania jest
 * logowany, a następna próba przypada za pełny interwał (bez gorącej pętli ponowień). Zegar
 * i uśpienie są wstrzykiwane, żeby test przeliczał minuty w mgnieniu oka.
 */
class Seller360Scheduler
{
    /** @var \Closure(): float */
    private \Closure $now;

    /** @var \Closure(int): void */
    private \Closure $sleep;

    /**
     * @param  iterable<Seller360Job>  $jobs
     * @param  (\Closure(): float)|null  $now
     * @param  (\Closure(int): void)|null  $sleep
     */
    public function __construct(
        private readonly iterable $jobs,
        private readonly LoggerInterface $logger,
        ?\Closure $now = null,
        ?\Closure $sleep = null,
    ) {
        $this->now = $now ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param  \Closure(): bool  $shouldStop  sprawdzane przed każdym zadaniem i przed każdym uśpieniem
     */
    public function run(\Closure $shouldStop): void
    {
        $jobs = [...$this->jobs];
        /** @var array<string, float> $lastRun */
        $lastRun = [];

        while (! $shouldStop()) {
            foreach ($jobs as $job) {
                if ($shouldStop()) {
                    return;
                }
                $now = ($this->now)();
                $name = $job->name();
                if (isset($lastRun[$name]) && $now - $lastRun[$name] < $job->intervalSeconds()) {
                    continue;
                }
                $lastRun[$name] = $now;
                try {
                    $job->run();
                } catch (\Throwable $e) {
                    $this->logger->error('Zadanie seller360 zakończone błędem.', ['job' => $name, 'error' => $e->getMessage(), 'exception' => $e]);
                }
            }
            if ($shouldStop()) {
                return;
            }
            ($this->sleep)(1);
        }
    }
}
