<?php

namespace App\Support\RaceTest;

/**
 * Werdykt `orders:race-test` bez bazy i procesów, żeby arytmetykę
 * „zaliczone / niezaliczone” dało się sprawdzić testem jednostkowym.
 */
final readonly class RaceOutcome
{
    public function __construct(
        public int $buyers,
        public int $stock,
        public int $paid,
    ) {}

    /**
     * DLACZEGO minimum: przy mniejszej liczbie kupujących niż sztuk każdy
     * powinien zapłacić, a „opłacone = stan” dawałoby fałszywy błąd.
     */
    public static function expectedPaid(int $buyers, int $stock): int
    {
        return min($buyers, $stock);
    }

    /** Dodatni wynik to sprzedanie sztuk, których nie było. */
    public function overselling(): int
    {
        return $this->paid - $this->stock;
    }

    /** Komunikat porażki albo null, gdy przebieg zaliczony. */
    public function failure(): ?string
    {
        if ($this->overselling() > 0) {
            return "OVERSELLING: opłacono {$this->paid} przy stanie {$this->stock}.";
        }

        $expected = self::expectedPaid($this->buyers, $this->stock);

        if ($this->paid !== $expected) {
            return "Opłacone {$this->paid}, oczekiwane {$expected}.";
        }

        return null;
    }

    /**
     * Percentyl metodą najbliższej rangi: przy kilkudziesięciu próbkach
     * interpolacja udawałaby precyzję, której pomiar nie ma.
     *
     * @param  list<float>  $sorted  posortowane rosnąco
     */
    public static function percentile(array $sorted, int $p): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        $rank = (int) ceil($p / 100 * count($sorted));

        return $sorted[max(0, $rank - 1)];
    }
}
