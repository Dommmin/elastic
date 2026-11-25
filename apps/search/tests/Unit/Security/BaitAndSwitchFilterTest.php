<?php

namespace App\Tests\Unit\Security;

use App\Security\Detect\PriceBaitAndSwitchDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Post-filtr `accept` reguły `price-bait-and-switch`: zmiany ≤ 30% i cena końcowa ≥ 1,3 × cena sprzed obniżki. */
final class BaitAndSwitchFilterTest extends TestCase
{
    /** @return array{event: array{type: string}, offer: array<string, int|float>} */
    private static function change(int $old, int $new, float $pct): array
    {
        return ['event' => ['type' => 'offer.price_changed'], 'offer' => ['old_price_cents' => $old, 'new_price_cents' => $new, 'change_pct' => $pct]];
    }

    private static function click(): array
    {
        return ['event' => ['type' => 'user.clicked_result'], 'offer' => ['id' => 'o1']];
    }

    /** @return iterable<string, array{0: list<array<string, mixed>>, 1: bool}> */
    public static function sequences(): iterable
    {
        yield 'cena końcowa 1,11x - za mało' => [[self::change(10000, 8000, -20), self::change(8000, 9600, 20), self::change(9600, 11136, 16)], false];
        yield 'końcowa 1,31x - alert' => [[self::change(10000, 8000, -20), self::change(8000, 10320, 29), self::change(10320, 13106, 27)], true];
        yield 'końcowa 1,30x - granica włącznie' => [[self::change(10000, 8000, -20), self::change(8000, 10320, 29), self::change(10320, 13003, 26)], true];
        yield 'końcowa 1,29x - bliskie pudło' => [[self::change(10000, 8000, -20), self::change(8000, 10240, 28), self::change(10240, 12902, 26)], false];
        yield 'obniżka -31 - odpada' => [[self::change(10000, 6900, -31), self::change(6900, 9000, 30), self::change(9000, 13000, 30)], false];
        yield 'podwyżka +31 - odpada' => [[self::change(10000, 8000, -20), self::change(8000, 10480, 31), self::change(10480, 13106, 25)], false];
        yield 'kliknięcia w sekwencji są pomijane' => [[self::change(10000, 8000, -20), self::click(), self::click(), self::change(8000, 10320, 29), self::change(10320, 13106, 27)], true];
        yield 'brak change_pct - odpada' => [[self::change(10000, 8000, -20), ['event' => ['type' => 'offer.price_changed'], 'offer' => ['old_price_cents' => 8000, 'new_price_cents' => 13000]]], false];
        yield 'pusta sekwencja' => [[], false];
    }

    /** @param list<array<string, mixed>> $events */
    #[DataProvider('sequences')]
    public function test_accept(array $events, bool $expected): void
    {
        self::assertSame($expected, PriceBaitAndSwitchDetector::accept($events));
    }
}
