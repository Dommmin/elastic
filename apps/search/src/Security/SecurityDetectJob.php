<?php

namespace App\Security;

use App\Seller360\Seller360Job;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Job harmonogramu `seller360-scheduler` (co 300 s): uruchamia wszystkie detektory za ostatnie 2 h
 * i zapisuje alerty idempotentnie. Dwie godziny z zapasem, bo kubełek reguły to 1 h, a przebieg
 * może się spóźnić; powtórne wykrycie nadpisuje ten sam dokument (id z klucza alertu).
 * Tę samą logikę (`detect`) woła komenda `security:detect` z dowolnym zakresem.
 *
 * Każdy detektor biegnie we własnym try/catch: awaria jednego (np. ES|QL) jest logowana z jego id
 * i nie odbiera przebiegu pozostałym; dopiero na końcu job rzuca, żeby scheduler zobaczył błąd.
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
        private readonly LoggerInterface $logger = new NullLogger(),
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
        $failures = [];
        $this->detect($to->modify('-'.self::LOOKBACK), $to, null, $failures);

        if ($failures !== []) {
            throw new \RuntimeException(\sprintf('security-detect: awaria detektorów: %s', implode(', ', array_keys($failures))), 0, reset($failures));
        }
    }

    /**
     * @param  array<string, \Throwable>  $failures wypełniane: rule.id => wyjątek detektora, który padł
     * @return array<string, int> rule.id => liczba zapisanych alertów (tylko wybrany detektor przy $ruleId, bez padniętych)
     */
    public function detect(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $ruleId = null, array &$failures = []): array
    {
        $counts = [];
        foreach ($this->detectors as $detector) {
            if ($ruleId !== null && $detector->id() !== $ruleId) {
                continue;
            }
            try {
                $counts[$detector->id()] = $this->writer->write($detector->detect($from, $to));
            } catch (\Throwable $e) {
                $failures[$detector->id()] = $e;
                $this->logger->error(\sprintf('Detektor %s padł: %s', $detector->id(), $e->getMessage()), ['exception' => $e]);
            }
        }

        return $counts;
    }
}
