<?php

namespace App\Analytics;

/**
 * Skłonność do obejrzenia pozycji `p(k)` (ETAP 12, spec §6) — szacowana z danych,
 * nie z parametrów symulatora (korekta nie może „znać odpowiedzi”).
 *
 * `p(k) = ctr(k) / ctr(1)` po izotonicznej korekcie CTR (pool adjacent violators,
 * wagi = wyświetlenia): realne `p(k)` nie rośnie z pozycją, a szum (ctr(5) > ctr(4))
 * dałby dalszej pozycji WIĘKSZĄ wagę 1/p niż bliższej — korekta uśrednia takie pary.
 * Dolna granica 0,01 ogranicza wagę pojedynczego kliknięcia do 100.
 */
final class PositionPropensity
{
    public const MIN = 0.01;

    /**
     * @param array<int, array{impressions:int, clicks:int}> $counts pozycja (od 1) => liczniki
     *
     * @return array<int, float> pozycja => p(k), klucze rosnąco, p(1) = 1
     */
    public static function fromCounts(array $counts): array
    {
        ksort($counts);
        if (array_key_first($counts) !== 1 || $counts[1]['impressions'] <= 0) {
            throw new \InvalidArgumentException('p(k): brak wyświetleń na pozycji 1 — nie ma względem czego normalizować.');
        }

        // Pule PAV: [suma kliknięć, suma wyświetleń, pozycje]. Pozycje bez wyświetleń
        // nie mają CTR — dziedziczą wartość poprzedniej pozycji (monotoniczność zostaje).
        $pools = [];
        foreach ($counts as $k => $c) {
            if ($c['impressions'] <= 0) {
                continue;
            }
            $pools[] = ['clicks' => (float) $c['clicks'], 'impressions' => (float) $c['impressions'], 'positions' => [$k]];
            while (\count($pools) > 1 && self::rate($pools[\count($pools) - 1]) > self::rate($pools[\count($pools) - 2])) {
                $last = array_pop($pools);
                $prev = array_pop($pools);
                $pools[] = [
                    'clicks' => $prev['clicks'] + $last['clicks'],
                    'impressions' => $prev['impressions'] + $last['impressions'],
                    'positions' => [...$prev['positions'], ...$last['positions']],
                ];
            }
        }

        $ctr = [];
        foreach ($pools as $pool) {
            foreach ($pool['positions'] as $k) {
                $ctr[$k] = self::rate($pool);
            }
        }

        $top = $ctr[1];
        if ($top <= 0.0) {
            throw new \InvalidArgumentException('p(k): brak kliknięć na pozycji 1 — CTR pozycji 1 równy 0.');
        }

        $p = [];
        $previous = 1.0;
        foreach (array_keys($counts) as $k) {
            $value = isset($ctr[$k]) ? $ctr[$k] / $top : $previous;
            $p[$k] = max(self::MIN, $value);
            $previous = $p[$k];
        }

        return $p;
    }

    /** @param array{clicks: float, impressions: float} $pool */
    private static function rate(array $pool): float
    {
        return $pool['clicks'] / $pool['impressions'];
    }
}
