<?php

namespace App\Security;

use App\Seller360\Seller360Job;

/**
 * Job harmonogramu `seller360-scheduler` (co 300 s): uruchamia wszystkie detektory za ostatnie 2 h
 * i zapisuje alerty idempotentnie. Dwie godziny z zapasem, bo kubełek reguły to 1 h, a przebieg
 * może się spóźnić; powtórne wykrycie nadpisuje ten sam dokument (id z klucza alertu).
 * Tę samą logikę (`detect`) woła komenda `security:detect` z dowolnym zakresem.
 */
final class SecurityDetectJob implements Seller360Job
{
    private const LOOKBACK = '2 hours';

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $now;

    /**
     * @param  iterable<Detector>  $detectors
     * @param  (\Closure(): \DateTimeImmutable)|null  $now
     */
    public function __construct(
        private readonly iterable $detectors,
        private readonly AlertWriter $writer,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    public function name(): string
    {
        return 'security-detect';
    }

    public function intervalSeconds(): int
    {
        return 300;
    }

    public function run(): void
    {
        $to = ($this->now)();
        $this->detect($to->modify('-'.self::LOOKBACK), $to);
    }

    /** @return array<string, int> rule.id => liczba zapisanych alertów (tylko wybrany detektor przy $ruleId) */
    public function detect(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $ruleId = null): array
    {
        $counts = [];
        foreach ($this->detectors as $detector) {
            if ($ruleId !== null && $detector->id() !== $ruleId) {
                continue;
            }
            $counts[$detector->id()] = $this->writer->write($detector->detect($from, $to));
        }

        return $counts;
    }
}
