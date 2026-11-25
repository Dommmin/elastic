<?php

namespace App\Tests\Unit\Security;

use App\Security\Detect\ComplaintsSpikeDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Próg reguły `complaints-spike`: dzień ≥ średnia + 3σ i co najmniej 5 reklamacji. */
final class ComplaintsSpikeRuleTest extends TestCase
{
    /** @return iterable<string, array{int, ?float, ?float, bool}> */
    public static function cases(): iterable
    {
        yield '12 przy średniej 2 i σ 1' => [12, 2.0, 1.0, true];
        yield 'poniżej minimum 5' => [4, 0.0, 0.0, false];
        yield 'dokładnie na progu 5 >= 4' => [5, 1.0, 1.0, true];
        yield '6 < 6.5' => [6, 2.0, 1.5, false];
        yield 'brak historii (średnia null)' => [8, null, null, false];
    }

    #[DataProvider('cases')]
    public function test_is_spike(int $count, ?float $mean, ?float $std, bool $expected): void
    {
        self::assertSame($expected, ComplaintsSpikeDetector::isSpike($count, $mean, $std));
    }
}
