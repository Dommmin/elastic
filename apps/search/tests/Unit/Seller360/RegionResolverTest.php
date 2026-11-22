<?php

namespace App\Tests\Unit\Seller360;

use App\Seller360\RegionResolver;
use PHPUnit\Framework\TestCase;

final class RegionResolverTest extends TestCase
{
    private const CSV = '/infra/reference/miasta-wojewodztwa.csv';

    private function resolver(): RegionResolver
    {
        if (! is_file(self::CSV)) {
            self::markTestSkipped('Brak '.self::CSV.' (montowany w kontenerach search).');
        }

        return new RegionResolver(self::CSV);
    }

    public function test_krakow_to_malopolskie(): void
    {
        self::assertSame('małopolskie', $this->resolver()->forCity('Kraków'));
    }

    public function test_trim_wielkosc_liter_i_brak_polskich_znakow_nie_przeszkadzaja(): void
    {
        $resolver = $this->resolver();

        self::assertSame('mazowieckie', $resolver->forCity(' warszawa '));
        self::assertSame('małopolskie', $resolver->forCity('krakow'));
        self::assertSame('łódzkie', $resolver->forCity('LODZ'));
    }

    public function test_miasto_spoza_listy_daje_null(): void
    {
        self::assertNull($this->resolver()->forCity('Wąchock'));
        self::assertNull($this->resolver()->forCity(''));
    }

    public function test_plik_ma_co_najmniej_300_miast_i_wszystkie_16_wojewodztw(): void
    {
        $resolver = $this->resolver();

        self::assertGreaterThanOrEqual(300, $resolver->cityCount());
        self::assertCount(16, $resolver->regions());
    }

    public function test_brak_pliku_to_czytelny_wyjatek(): void
    {
        $this->expectException(\RuntimeException::class);

        (new RegionResolver('/nie/ma/takiego.csv'))->forCity('Kraków');
    }
}
