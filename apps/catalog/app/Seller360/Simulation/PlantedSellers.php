<?php

namespace App\Seller360\Simulation;

use InvalidArgumentException;
use Random\Randomizer;

/**
 * Zasadzeni sprzedawcy i bliskie pudła (prawda podstawowa dla dowodu z Task 12).
 *
 * Zasadzony spełnia wszystkie cztery warunki zapytania z dema: region `mazowieckie`, spadek konwersji
 * w [0,3; 0,5] przy `cart_sessions_prev ≥ 40`, `overdue_cents > 0` i reklamacja z frazą „uszkodzon”.
 * Bliskie pudło (po jednym na warunek) spełnia trzy z nich, a czwartego nie — zapytanie, które go
 * zwróci, ma precyzję < 1. Role przydzielane są losowo spośród indeksów syntetycznych sprzedawców.
 */
final class PlantedSellers
{
    public const ROLE_PLANTED = 'planted';

    /** Brakujący warunek bliskiego pudła, w kolejności przydziału. */
    public const MISSING = ['region', 'drop', 'overdue', 'complaint'];

    public const REGION = 'mazowieckie';

    public const PHRASE = 'uszkodzon*';

    /**
     * @return array<int, string> indeks syntetycznego sprzedawcy → rola (`planted` albo `near:<warunek>`)
     */
    public static function assign(Randomizer $random, int $sellers, int $plant): array
    {
        if ($plant === 0) {
            return [];
        }
        $needed = $plant + count(self::MISSING);
        if ($sellers < $needed) {
            throw new InvalidArgumentException("--plant={$plant} wymaga co najmniej {$needed} syntetycznych sprzedawców (zasadzeni + 4 bliskie pudła).");
        }

        $indices = array_slice($random->shuffleArray(range(0, $sellers - 1)), 0, $needed);
        $roles = [];
        foreach ($indices as $n => $index) {
            $roles[$index] = $n < $plant ? self::ROLE_PLANTED : 'near:'.self::MISSING[$n - $plant];
        }

        return $roles;
    }

    public static function missing(?string $role): ?string
    {
        return $role !== null && str_starts_with($role, 'near:') ? substr($role, 5) : null;
    }

    /** Czy sprzedawca ma spełniać warunek (zasadzony: wszystkie; bliskie pudło: wszystkie poza swoim). */
    public static function satisfies(?string $role, string $condition): bool
    {
        return $role === self::ROLE_PLANTED || ($role !== null && self::missing($role) !== $condition);
    }

    /**
     * @param  list<int>  $planted
     * @param  list<array{id: int, missing: string}>  $nearMisses
     */
    public static function write(string $path, array $planted, array $nearMisses): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, json_encode([
            'planted' => $planted,
            'near_misses' => $nearMisses,
            'region' => self::REGION,
            'phrase' => self::PHRASE,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }
}
