<?php

namespace App\Seller360;

/**
 * Ciało `_search` na `seller-360` z filtrów ekranu konsultanta (ETAP 13).
 *
 * Każdy filtr jest opcjonalny, a brak sekcji w dokumencie nigdy nie wywraca zapytania:
 * `range` na nieistniejącym polu po prostu nie pasuje, a sortowanie po sprzedaży ma
 * `missing: _last` (sprzedawcy bez sekcji `sales` na końcu) i `unmapped_type` (pusty indeks).
 * Warunki zawężające idą do `filter` (bez liczenia trafności, z cache filtrów), a jedyny
 * warunek „szukaj” (`q`) do `must`, bo tylko on ma sens w kolejności po `_score`.
 */
final class Seller360Query
{
    public const DEFAULT_SIZE = 25;

    /** Pola wyszukiwarki: nazwa, NIP, miasto i treść ostatnich reklamacji. */
    private const SEARCH_FIELDS = ['profile.name', 'nip', 'profile.city', 'complaints.recent_texts'];

    /**
     * Zapytanie z DoD ETAPU 13 (`04` §5): województwo, spadek konwersji > 20%, zaległość,
     * „uszkodzon*” w reklamacjach, sortowanie po sprzedaży.
     *
     * @return array{region: string, min_drop: float, overdue: bool, complaint_phrase: string, sort: string}
     */
    public static function dodPreset(): array
    {
        return ['region' => 'mazowieckie', 'min_drop' => 0.2, 'overdue' => true, 'complaint_phrase' => 'uszkodzon*', 'sort' => 'gmv'];
    }

    /**
     * @param  array<string, mixed>  $f  q, region, vat_status, overdue, min_drop, complaint_phrase, courier, sort
     * @return array<string, mixed>
     */
    public static function fromFilters(array $f, int $page = 1, int $size = self::DEFAULT_SIZE): array
    {
        $must = [];
        $filter = [];

        $q = self::text($f['q'] ?? null);
        if ($q !== null) {
            $must[] = ['multi_match' => ['query' => $q, 'fields' => self::SEARCH_FIELDS, 'lenient' => true]];
        }

        if (($region = self::text($f['region'] ?? null)) !== null) {
            $filter[] = ['term' => ['region' => $region]];
        }
        if (($vat = self::text($f['vat_status'] ?? null)) !== null) {
            $filter[] = ['term' => ['registry.vat_status' => $vat]];
        }
        if (filter_var($f['overdue'] ?? false, FILTER_VALIDATE_BOOL)) {
            $filter[] = ['range' => ['billing.overdue_cents' => ['gt' => 0]]];
        }
        $minDrop = $f['min_drop'] ?? null;
        if (is_numeric($minDrop)) {
            $filter[] = ['range' => ['sales.conversion_drop' => ['gte' => (float) $minDrop]]];
        }
        if (($phrase = self::text($f['complaint_phrase'] ?? null)) !== null) {
            // Wzorzec z gwiazdką przechodzi przez analizator polski pola (analyze_wildcard),
            // więc „uszkodzon*” trafia „uszkodzony”, „uszkodzona” i „uszkodzone”.
            $filter[] = ['simple_query_string' => [
                'query' => $phrase,
                'fields' => ['complaints.recent_texts'],
                'analyze_wildcard' => true,
                'default_operator' => 'and',
            ]];
        }
        if (($courier = self::text($f['courier'] ?? null)) !== null) {
            $filter[] = ['term' => ['registry.courier.id' => $courier]];
        }

        if ($must === [] && $filter === []) {
            $query = ['match_all' => (object) []];
        } else {
            $query = ['bool' => array_filter(['must' => $must, 'filter' => $filter])];
        }

        $sort = [];
        if (($f['sort'] ?? null) === 'gmv') {
            $sort[] = ['sales.gmv_30d_cents' => ['order' => 'desc', 'missing' => '_last', 'unmapped_type' => 'long']];
        } elseif ($q !== null) {
            $sort[] = '_score';
        }
        // Remis (albo brak innego kryterium) rozstrzyga seller_id, więc strony są stabilne.
        $sort[] = ['seller_id' => ['order' => 'asc']];

        return [
            'query' => $query,
            'sort' => $sort,
            'from' => (max(1, $page) - 1) * $size,
            'size' => $size,
            'track_total_hits' => true,
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
