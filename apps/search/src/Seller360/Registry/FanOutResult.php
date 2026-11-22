<?php

namespace App\Seller360\Registry;

/**
 * Wynik jednego przebiegu `CourierFanOut::poll`: liczby zsumowane po wszystkich kurierach i indeksach docelowych.
 * `maxRatedAt` (najnowsze `rated_at` z odpowiedzi rejestru, null bez zmian) wyznacza nowe `since`.
 */
final readonly class FanOutResult
{
    public function __construct(
        public int $couriers,
        public int $updated,
        public int $noops,
        public int $conflicts,
        public int $tookMs,
        public ?string $maxRatedAt = null,
    ) {
    }
}
