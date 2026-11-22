<?php

namespace App\Seller360\Registry;

/**
 * Bezpiecznik rejestru w Redisie (współdzielony przez procesy): po 5 kolejnych porażkach otwiera się
 * na 30 s. Po upływie czasu przepuszcza (stan półotwarty); porażka otwiera ponownie od razu,
 * bo licznik nie jest zerowany, a sukces zeruje wszystko.
 */
class CircuitBreaker
{
    public const THRESHOLD = 5;
    public const OPEN_SECONDS = 30;

    private const KEY_FAILURES = 'seller360:registry:breaker:failures';
    private const KEY_OPEN_UNTIL = 'seller360:registry:breaker:open_until';

    /** @var \Closure(): float */
    private readonly \Closure $now;

    /**
     * @param  (\Closure(): float)|null  $now  zegar w sekundach (podmieniany w testach)
     */
    public function __construct(private readonly \Redis $redis, ?\Closure $now = null)
    {
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    public function allow(): bool
    {
        return ! $this->isOpen();
    }

    /** Odczyt dla metryki `seller360_registry_breaker_open` (Task 10). */
    public function isOpen(): bool
    {
        return $this->openUntil() > ($this->now)();
    }

    public function success(): void
    {
        $this->redis->del(self::KEY_FAILURES, self::KEY_OPEN_UNTIL);
    }

    public function failure(): void
    {
        $failures = (int) $this->redis->incr(self::KEY_FAILURES);
        $this->redis->expire(self::KEY_FAILURES, 3600);
        if ($failures >= self::THRESHOLD) {
            $this->redis->set(self::KEY_OPEN_UNTIL, (string) (($this->now)() + self::OPEN_SECONDS), ['EX' => 3600]);
        }
    }

    private function openUntil(): float
    {
        return (float) ($this->redis->get(self::KEY_OPEN_UNTIL) ?: 0);
    }
}
