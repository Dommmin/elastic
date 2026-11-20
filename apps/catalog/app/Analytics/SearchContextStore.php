<?php

namespace App\Analytics;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Kontekst wyszukiwania `search_id -> {query, results}` w Redisie na 30 minut.
 *
 * Kliknięcie i koszyk dociągają stąd frazę, żeby każde zdarzenie niosło `query`
 * (spec sekcja 4). Awaria Redisa nie może zepsuć wyszukiwania: błąd jest logowany,
 * `put` nic nie robi, `get` zwraca null.
 */
class SearchContextStore
{
    public const TTL_SECONDS = 1800;

    /**
     * Dopuszczalny `search_id` z wejścia (URL, formularz): alfabet ULID/UUID,
     * do 64 znaków. Nic spoza niego nie trafia do klucza w Redisie.
     */
    public const SEARCH_ID_PATTERN = '/^[0-9A-Za-z-]{1,64}$/';

    public static function isValidSearchId(mixed $value): bool
    {
        return is_string($value) && preg_match(self::SEARCH_ID_PATTERN, $value) === 1;
    }

    /**
     * @param  list<int>  $results
     */
    public function put(string $searchId, string $query, array $results): void
    {
        try {
            Redis::setex(self::key($searchId), self::TTL_SECONDS, json_encode(['query' => $query, 'results' => $results], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            Log::warning('Analityka: nie zapisano kontekstu wyszukiwania', ['search_id' => $searchId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{query: string, results: list<int>}|null
     */
    public function get(string $searchId): ?array
    {
        try {
            $raw = Redis::get(self::key($searchId));
            if (! is_string($raw)) {
                return null;
            }
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return [
                'query' => (string) ($decoded['query'] ?? ''),
                'results' => array_values(array_map('intval', (array) ($decoded['results'] ?? []))),
            ];
        } catch (Throwable $e) {
            Log::warning('Analityka: nie odczytano kontekstu wyszukiwania', ['search_id' => $searchId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private static function key(string $searchId): string
    {
        return 'analytics:search:'.$searchId;
    }
}
