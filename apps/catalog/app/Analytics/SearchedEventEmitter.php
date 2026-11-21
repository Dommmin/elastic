<?php

namespace App\Analytics;

use App\Http\Middleware\AnalyticsSession;
use App\Services\Search\SearchCriteria;
use App\Services\Search\SearchResult;
use Illuminate\Http\Request;

/**
 * Składa i zapisuje `user.searched` — wspólne dla strony Inertii i API JSON.
 * Wołać tylko dla pierwszej strony wyników (bez kursora).
 */
class SearchedEventEmitter
{
    public const TOP_RESULTS = 20;

    public function __construct(
        private readonly AnalyticsRecorder $recorder,
        private readonly SearchContextStore $contexts,
    ) {}

    public function emit(Request $request, string $searchId, SearchCriteria $criteria, SearchResult $result): void
    {
        $query = mb_strtolower($criteria->q);
        $results = array_map(
            static fn (array $item): int => (int) $item['id'],
            array_slice($result->items, 0, self::TOP_RESULTS),
        );

        $data = [
            'search_id' => $searchId,
            'query' => $query,
            'query_raw' => $criteria->q,
            'filters' => $this->activeFilters($criteria),
            'total_hits' => (int) $result->total['value'],
            'results' => $results,
            'sort' => $criteria->sort,
        ];

        if ($request->user() !== null) {
            $data['user_id'] = $request->user()->getAuthIdentifier();
        }

        $this->recorder->record('user.searched', AnalyticsSession::currentId($request), $data);
        $this->contexts->put($searchId, $query, $results);
    }

    /**
     * Tylko filtry faktycznie ustawione (bez q, sortu i rozmiaru strony).
     *
     * @return array<string, mixed>
     */
    private function activeFilters(SearchCriteria $criteria): array
    {
        $filters = array_diff_key($criteria->toArray(), ['q' => 1, 'sort' => 1, 'per_page' => 1]);

        return array_filter($filters, static fn (mixed $v): bool => $v !== null && $v !== []);
    }
}
