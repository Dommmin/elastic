<?php

namespace App\Services\Search\Lab;

/**
 * Reciprocal Rank Fusion w PHP (wariant `rrf-client`) — ten sam wzór co retriever `rrf`
 * w ES: wynik dokumentu = Σ `1 / (rank_constant + pozycja)` po listach, pozycje od 1,
 * z każdej listy liczy się tylko pierwsze `window` pozycji (`rank_window_size`).
 *
 * Remis (ta sama suma) rozstrzyga mniejsze id porównane jako string. ES rozstrzyga remis
 * inaczej (wewnętrzny numer dokumentu), więc kolejność wewnątrz remisu może się różnić
 * między `rrf-client` i `rrf-native` — `search:eval:lab --check-rrf` dopuszcza tylko to.
 */
final class Rrf
{
    /**
     * @param  array<int, array<int, int|string>>  $rankings  listy id w kolejności wyników
     * @return array<string, float> id => suma RRF, w kolejności pierwszego wystąpienia
     */
    public static function scores(array $rankings, int $rankConstant = 60, int $window = 100): array
    {
        $scores = [];

        foreach ($rankings as $ranking) {
            foreach (array_slice(array_values($ranking), 0, $window) as $i => $id) {
                $id = (string) $id;
                $scores[$id] = ($scores[$id] ?? 0.0) + 1 / ($rankConstant + $i + 1);
            }
        }

        return $scores;
    }

    /**
     * @param  array<int, array<int, int|string>>  $rankings
     * @return list<string> id posortowane malejąco po sumie RRF
     */
    public static function fuse(array $rankings, int $rankConstant = 60, int $window = 100): array
    {
        $scores = self::scores($rankings, $rankConstant, $window);
        $order = array_flip(array_map('strval', array_keys($scores)));
        $ids = array_map('strval', array_keys($scores));

        usort($ids, static fn (string $a, string $b): int => ($scores[$b] <=> $scores[$a])
            ?: (strcmp($a, $b) ?: $order[$a] <=> $order[$b]));

        return $ids;
    }
}
