<?php

namespace App\Tests\Unit\Analytics;

use App\Analytics\PositionPropensity;
use PHPUnit\Framework\TestCase;

final class PositionPropensityTest extends TestCase
{
    public function test_p_to_ctr_pozycji_znormalizowany_do_pozycji_1(): void
    {
        $p = PositionPropensity::fromCounts([
            1 => ['impressions' => 100, 'clicks' => 20],
            2 => ['impressions' => 100, 'clicks' => 10],
        ]);

        self::assertEqualsWithDelta(1.0, $p[1], 1e-12);
        self::assertEqualsWithDelta(0.5, $p[2], 1e-12);
    }

    public function test_szum_wygladzony_do_monotonicznie_nierosnacego(): void
    {
        // ctr(5)=0.07 > ctr(4)=0.05 — szum; izotoniczna korekta uśrednia parę (wagi = wyświetlenia).
        $p = PositionPropensity::fromCounts([
            1 => ['impressions' => 100, 'clicks' => 20],
            2 => ['impressions' => 100, 'clicks' => 10],
            3 => ['impressions' => 100, 'clicks' => 8],
            4 => ['impressions' => 100, 'clicks' => 5],
            5 => ['impressions' => 100, 'clicks' => 7],
            6 => ['impressions' => 100, 'clicks' => 4],
        ]);

        self::assertSame([1, 2, 3, 4, 5, 6], array_keys($p));
        for ($k = 2; $k <= 6; ++$k) {
            self::assertLessThanOrEqual($p[$k - 1], $p[$k], "p({$k}) > p(".($k - 1).')');
        }
        self::assertEqualsWithDelta(0.3, $p[4], 1e-12, '(0.05 + 0.07) / 2 / 0.2');
        self::assertEqualsWithDelta(0.3, $p[5], 1e-12);
        self::assertEqualsWithDelta(0.4, $p[3], 1e-12, 'bez naruszenia — bez zmian');
        self::assertEqualsWithDelta(0.2, $p[6], 1e-12);
    }

    public function test_wagi_to_wyswietlenia(): void
    {
        // Pozycja z 3× większą ekspozycją ciągnie średnią puli 3× mocniej.
        $p = PositionPropensity::fromCounts([
            1 => ['impressions' => 100, 'clicks' => 10],
            2 => ['impressions' => 300, 'clicks' => 15],  // 0.05
            3 => ['impressions' => 100, 'clicks' => 9],   // 0.09 > 0.05
        ]);

        self::assertEqualsWithDelta((15 + 9) / 400 / 0.1, $p[2], 1e-12);
        self::assertEqualsWithDelta($p[2], $p[3], 1e-12);
    }

    public function test_naruszenie_na_pozycji_1_normalizuje_po_wygladzeniu(): void
    {
        // ctr(2) > ctr(1): pula {1,2}, więc p(1) = p(2) = 1, a reszta względem średniej puli.
        $p = PositionPropensity::fromCounts([
            1 => ['impressions' => 100, 'clicks' => 10],
            2 => ['impressions' => 100, 'clicks' => 30],
            3 => ['impressions' => 100, 'clicks' => 10],
        ]);

        self::assertEqualsWithDelta(1.0, $p[1], 1e-12);
        self::assertEqualsWithDelta(1.0, $p[2], 1e-12);
        self::assertEqualsWithDelta(0.5, $p[3], 1e-12);
    }

    public function test_dolna_granica_0_01(): void
    {
        $p = PositionPropensity::fromCounts([
            1 => ['impressions' => 1000, 'clicks' => 500],
            2 => ['impressions' => 1000, 'clicks' => 1],
            3 => ['impressions' => 1000, 'clicks' => 0],
        ]);

        self::assertSame(0.01, $p[2]);
        self::assertSame(0.01, $p[3]);
    }

    public function test_pozycja_bez_wyswietlen_dziedziczy_po_poprzedniej(): void
    {
        $p = PositionPropensity::fromCounts([
            1 => ['impressions' => 100, 'clicks' => 20],
            2 => ['impressions' => 100, 'clicks' => 10],
            3 => ['impressions' => 0, 'clicks' => 0],
            4 => ['impressions' => 100, 'clicks' => 4],
        ]);

        self::assertEqualsWithDelta(0.5, $p[3], 1e-12);
        self::assertEqualsWithDelta(0.2, $p[4], 1e-12);
    }

    public function test_klucze_w_dowolnej_kolejnosci_wynik_posortowany(): void
    {
        $p = PositionPropensity::fromCounts([
            2 => ['impressions' => 100, 'clicks' => 10],
            1 => ['impressions' => 100, 'clicks' => 20],
        ]);

        self::assertSame([1, 2], array_keys($p));
    }

    public function test_brak_klikniec_na_pozycji_1_to_blad(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PositionPropensity::fromCounts([
            1 => ['impressions' => 100, 'clicks' => 0],
            2 => ['impressions' => 100, 'clicks' => 0],
        ]);
    }

    public function test_brak_wyswietlen_pozycji_1_to_blad(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PositionPropensity::fromCounts([
            1 => ['impressions' => 0, 'clicks' => 0],
            2 => ['impressions' => 100, 'clicks' => 5],
        ]);
    }
}
