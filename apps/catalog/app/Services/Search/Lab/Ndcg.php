<?php

namespace App\Services\Search\Lab;

/**
 * nDCG@k liczone po stronie PHP z tym samym wzorem co `_rank_eval` metryki
 * `dcg` z `normalize: true`: zysk `2^ocena - 1`, dyskonto `log2(pozycja + 1)`
 * (pozycje od 1). Ideał to posortowane malejąco WSZYSTKIE oceny zapytania
 * (nie tylko te, które wróciły) — oceniony dokument spoza wyników obniża wynik —
 * obcięte do min(k, liczba zwróconych wyników). To obcięcie robi `_rank_eval`
 * (sprawdzone na ES 9.5.1: jedno trafienie z oceną 1 przy ocenach {1, 3} daje 1/7,
 * nie 1/(7 + 1/log2 3) ≈ 0,131); zapytanie z mniej niż k wynikami nie jest karane za
 * puste pozycje, tylko za kolejność i dobór tego, co wróciło.
 *
 * Po co własny licznik: `_rank_eval` przyjmuje tylko zwykłe `query`, a
 * `rrf-native` to retriever `rrf`, `rrf-client` zaś łączy wyniki w PHP. Zapytania
 * na `semantic_text` (knn E5, `semantic` na ELSER) `_rank_eval` liczy tak samo
 * jak ten licznik (`--check-rank-eval`: 33/33 zgodnych); jeden licznik dla
 * wszystkich wariantów daje porównywalne liczby.
 */
final class Ndcg
{
    /**
     * @param  array<int, int|string>  $rankedIds  id dokumentów w kolejności wyników
     * @param  array<int|string, int>  $ratings  id => ocena
     */
    public static function atK(array $rankedIds, array $ratings, int $k): float
    {
        $ideal = array_values($ratings);
        rsort($ideal);
        $returned = min($k, count($rankedIds));
        $idealDcg = self::dcg(array_slice($ideal, 0, $returned));

        if ($idealDcg <= 0.0) {
            return 0.0;
        }

        $gains = [];
        foreach (array_slice(array_values($rankedIds), 0, $k) as $id) {
            $gains[] = $ratings[$id] ?? 0;
        }

        return self::dcg($gains) / $idealDcg;
    }

    /**
     * @param  array<int, int>  $grades
     */
    private static function dcg(array $grades): float
    {
        $sum = 0.0;
        foreach (array_values($grades) as $i => $grade) {
            $sum += (2 ** $grade - 1) / log($i + 2, 2);
        }

        return $sum;
    }
}
