<?php

namespace App\Analytics\Simulation;

/**
 * Ukryta atrakcyjność produktu `a ∈ (0,1)` — „prawda” symulatora, której
 * wyszukiwarka nie zna (spec ETAP 12, sekcja 8).
 *
 * Rozkład Beta(2,5) (średnia 2/7 ≈ 0.286, większość produktów przeciętna,
 * nieliczne bardzo atrakcyjne) liczony ze statystyki pozycyjnej: k-ta
 * najmniejsza z n niezależnych U(0,1) ma rozkład Beta(k, n+1−k), więc druga
 * najmniejsza z sześciu to dokładnie Beta(2,5). Sześć uniformów bierzemy
 * z SHA-256 pary (ziarno, ID) — wynik jest deterministyczny, bez globalnego
 * stanu generatora i niezależny od kolejności wywołań.
 */
final class Attractiveness
{
    /** Stała atrakcyjność dla eksperymentu kontrolnego (`--flat-attractiveness`). */
    public const FLAT = 0.3;

    public static function for(int $productId, int $seed): float
    {
        $words = unpack('N8', hash('sha256', "attractiveness|{$seed}|{$productId}", true));

        $uniforms = [];
        foreach (array_slice(array_values($words), 0, 6) as $word) {
            // +0.5 trzyma wynik ściśle w (0,1): ani 0, ani 1 nie wypadną.
            $uniforms[] = ($word + 0.5) / 4294967296;
        }
        sort($uniforms);

        return $uniforms[1];
    }
}
