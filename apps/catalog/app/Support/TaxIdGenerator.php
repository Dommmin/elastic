<?php

namespace App\Support;

use SellerPortal\Domain\Seller\TaxId;

/**
 * Deterministyczny generator poprawnych NIP-ów dla danych syntetycznych
 * (migracja istniejących sprzedawców, fabryki, generator ETAPU 13).
 *
 * DLACZEGO deterministyczny i różnowartościowy: ten sam `n` zawsze daje ten
 * sam NIP (powtarzalne seedy), a różne `n` nigdy się nie zderzą na
 * unikalnym indeksie. Wartości nie mają nic wspólnego z prawdziwymi firmami
 * poza tym, że przechodzą walidację sumy kontrolnej.
 *
 * Konstrukcja: `n` wyznacza pierwsze 8 cyfr (bez zera na początku), więc różne `n`
 * dają różne NIP-y. Dziewiątą cyfrę dobieramy tak, by reszta z sumy ważonej nie
 * wyszła 10 (takiego NIP-u nie ma): zaczynamy od 0, a gdy to nie działa, bierzemy 1.
 * Dla obu naraz reszta 10 nie wystąpi, bo waga 7 jest odwracalna modulo 11.
 */
final class TaxIdGenerator
{
    /** Najmniejszy prefiks: osiem cyfr bez zera na początku; ziarna do ~90 mln mieszczą się w 8 cyfrach. */
    private const int PREFIX_BASE = 10_000_000;

    private const int MAX_SEED = 89_999_999;

    public static function forSeed(int $n): string
    {
        if ($n < 0 || $n > self::MAX_SEED) {
            throw new \InvalidArgumentException('Ziarno NIP musi mieścić się w 0..'.self::MAX_SEED.'.');
        }

        $prefix = (string) (self::PREFIX_BASE + $n);

        foreach ([0, 1] as $ninth) {
            $base = $prefix.$ninth;
            $check = TaxId::checksum($base);

            if ($check !== 10) {
                return $base.$check;
            }
        }

        throw new \LogicException('Niemożliwe: dwie kolejne dziewiąte cyfry nie dają obu reszty 10.');
    }
}
