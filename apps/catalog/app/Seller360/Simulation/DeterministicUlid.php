<?php

namespace App\Seller360\Simulation;

use DateTimeInterface;
use Random\Randomizer;

/**
 * ULID (48 bitów czasu w ms + 80 bitów losowych) z generatora symulacji, jak w `analytics:simulate`:
 * to samo ziarno daje te same identyfikatory zdarzeń i faktur.
 */
final class DeterministicUlid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function make(DateTimeInterface $at, Randomizer $random): string
    {
        $time = '';
        $ms = (int) $at->format('Uv');
        for ($i = 0; $i < 10; $i++) {
            $time = self::ALPHABET[$ms % 32].$time;
            $ms = intdiv($ms, 32);
        }

        $bits = '';
        foreach (str_split($random->getBytes(10)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $rand = '';
        foreach (str_split($bits, 5) as $chunk) {
            $rand .= self::ALPHABET[bindec($chunk)];
        }

        return $time.$rand;
    }
}
