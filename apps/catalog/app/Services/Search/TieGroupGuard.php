<?php

namespace App\Services\Search;

use Elastic\Elasticsearch\Client;

/**
 * Strażnik determinizmu `search:eval` (docs/RUNBOOK.md #096): czy w top-k jest
 * grupa remisu `_score`, w której dokumenty mają RÓŻNE oceny?
 *
 * Kolejność wewnątrz grupy remisu rozstrzyga wewnętrzny doc id Lucene, więc
 * każda grupa, która choć jednym członkiem trafia do top-k, a ma mieszane oceny,
 * daje nDCG zależne od refreshy i merge'ów. Grupy w całości poza top-k nie mają
 * znaczenia.
 *
 * Grupy o `_score` WYŻSZYM niż k-ty mieszczą się w top-k w całości (jest ich
 * co najwyżej k), więc wystarczy top-k. Jedyna grupa, która może wystawać poza
 * k, to ta o wyniku RÓWNYM k-temu — jej członków czytamy WSZYSTKICH przez PIT
 * + `search_after`, bez limitu okna wyników (`max_result_window` = 10 000)
 * i bez stałego limitu trafień. Na 300 tys. produktów takie grupy mają ok. 27 tys.
 * dokumentów (cała kategoria liścia) — dawny limit 1000 widział ich ułamek.
 */
class TieGroupGuard
{
    private const PAGE_SIZE = 5_000;

    public function __construct(private readonly Client $client) {}

    /**
     * @param  array<string, mixed>  $query  ciało `query` zapytania ocenianego
     * @param  array<int|string, int>  $grades  id dokumentu => ocena (brak = 0)
     */
    public function hasMixedGradeTie(string $index, array $query, array $grades, int $k): bool
    {
        $top = $this->client->search([
            'index' => $index,
            'body' => ['query' => $query, 'size' => $k, '_source' => false, 'track_total_hits' => false],
        ])->asArray()['hits']['hits'];

        // Mniej niż k trafień: nie ma granicy top-k, którą grupa mogłaby przekroczyć,
        // ale kolejność w obrębie grupy nadal zmienia nDCG — sprawdzamy to samo.
        if ($top === []) {
            return false;
        }

        $boundaryScore = (string) end($top)['_score'];

        /** @var array<string, array<int, true>> $gradesByScore */
        $gradesByScore = [];

        foreach ($top as $hit) {
            if ((string) $hit['_score'] !== $boundaryScore) {
                $gradesByScore[(string) $hit['_score']][$grades[$hit['_id']] ?? 0] = true;
            }
        }

        $boundaryGrades = count($top) < $k
            ? $this->gradesOf($top, $grades)
            : $this->boundaryGroupGrades($index, $query, $grades, (float) end($top)['_score'], $boundaryScore);

        $gradesByScore[$boundaryScore] = $boundaryGrades;

        foreach ($gradesByScore as $distinctGrades) {
            if (count($distinctGrades) > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $hits
     * @param  array<int|string, int>  $grades
     * @return array<int, true>
     */
    private function gradesOf(array $hits, array $grades): array
    {
        $distinct = [];

        foreach ($hits as $hit) {
            $distinct[$grades[$hit['_id']] ?? 0] = true;
        }

        return $distinct;
    }

    /**
     * Oceny WSZYSTKICH dokumentów o `_score` równym k-temu (grupa może mieć dziesiątki tysięcy członków).
     *
     * @param  array<string, mixed>  $query
     * @param  array<int|string, int>  $grades
     * @return array<int, true>
     */
    private function boundaryGroupGrades(string $index, array $query, array $grades, float $boundary, string $boundaryKey): array
    {
        $pitId = $this->client->openPointInTime(['index' => $index, 'keep_alive' => '1m'])->asArray()['id'];
        $distinct = [];
        $searchAfter = null;

        try {
            do {
                $body = [
                    'pit' => ['id' => $pitId, 'keep_alive' => '1m'],
                    'query' => $query,
                    // `min_score` odcina wszystko poniżej grupy; wyżej jest ich najwyżej k.
                    'min_score' => $boundary,
                    'sort' => [['_score' => 'desc'], ['_shard_doc' => 'asc']],
                    'size' => self::PAGE_SIZE,
                    '_source' => false,
                    'track_total_hits' => false,
                ];

                if ($searchAfter !== null) {
                    $body['search_after'] = $searchAfter;
                }

                $hits = $this->client->search(['body' => $body])->asArray()['hits']['hits'];

                foreach ($hits as $hit) {
                    if ((string) $hit['_score'] === $boundaryKey) {
                        $distinct[$grades[$hit['_id']] ?? 0] = true;
                    }
                }

                $searchAfter = $hits === [] ? null : end($hits)['sort'];
            } while (count($hits) === self::PAGE_SIZE);
        } finally {
            $this->client->closePointInTime(['body' => ['id' => $pitId]]);
        }

        return $distinct;
    }
}
