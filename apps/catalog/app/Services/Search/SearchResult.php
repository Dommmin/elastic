<?php

namespace App\Services\Search;

/**
 * Wynik `ProductSearchService::search()` — surowa odpowiedź ES NIGDY nie
 * wycieka poza serwis (docs/02-APLIKACJE.md: Laravel zna kontrakt zapytań,
 * nie mapowanie). Kontrolery przekazują `toArray()` wprost jako propsy
 * Inertii / JSON.
 */
final readonly class SearchResult
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $facets
     * @param  array{value: int, isLowerBound: bool}  $total
     */
    public function __construct(
        public array $items,
        public array $facets,
        public array $total,
        public ?string $nextCursor,
        public int $tookMs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'facets' => $this->facets,
            'total' => $this->total,
            'next_cursor' => $this->nextCursor,
            'took_ms' => $this->tookMs,
        ];
    }
}
