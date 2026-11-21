<?php

namespace App\Analytics\Simulation;

use Closure;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Model kliknięć z biasem pozycji (examination hypothesis): użytkownik ogląda
 * pozycję `k` z prawdopodobieństwem `1/k^η` i klika obejrzany produkt
 * z prawdopodobieństwem równym jego ukrytej atrakcyjności `a`. Pozycje są
 * niezależne, więc jedno wyszukanie może dać kilka kliknięć.
 *
 * Szum: z prawdopodobieństwem `$noise` wyszukanie klika „na ślepo” — każda
 * pozycja ma to samo prawdopodobieństwo, równe średniej z modelu, więc
 * oczekiwana liczba kliknięć się nie zmienia, a znika zależność od pozycji
 * i atrakcyjności.
 */
final class ClickModel
{
    /**
     * @param  Closure(int): float  $attractiveness  ID produktu → a ∈ (0,1)
     */
    public function __construct(private readonly Closure $attractiveness) {}

    /**
     * Pozycje kliknięte (1-based, rosnąco). Ten sam `$seed` = te same kliknięcia.
     *
     * @param  list<int>  $results  ID produktów w kolejności rankingu
     * @param  float  $multiplier  mnożnik prawdopodobieństwa kliknięcia (anomalia `ctr-drop` = 0.2)
     * @return list<int>
     */
    public function clicks(array $results, int $seed, float $eta, float $noise, float $multiplier = 1.0): array
    {
        if ($results === []) {
            return [];
        }

        $random = new Randomizer(new Xoshiro256StarStar($seed));

        $probabilities = [];
        foreach (array_values($results) as $i => $productId) {
            $probabilities[$i + 1] = min(1.0, (1 / (($i + 1) ** $eta)) * ($this->attractiveness)($productId) * $multiplier);
        }

        if ($random->getFloat(0, 1) < $noise) {
            $uniform = array_sum($probabilities) / count($probabilities);
            $probabilities = array_fill_keys(array_keys($probabilities), $uniform);
        }

        $clicked = [];
        foreach ($probabilities as $position => $p) {
            if ($random->getFloat(0, 1) < $p) {
                $clicked[] = $position;
            }
        }

        return $clicked;
    }
}
