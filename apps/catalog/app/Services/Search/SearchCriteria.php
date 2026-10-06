<?php

namespace App\Services\Search;

use Illuminate\Http\Request;

/**
 * Znormalizowane wejście do `ProductSearchService` — jeden obiekt zamiast
 * przekazywania osobno `q`, filtrów, sortu itd. przez wszystkie warstwy
 * (kontroler Inertii, kontroler JSON, komenda `search:eval`). D-09: to jest
 * "kontrakt zapytań", o którym mówi docs/02-APLIKACJE.md — Laravel zna TEN
 * kształt, nie mapowanie ES.
 *
 * Ceny (`priceMin`/`priceMax`) są w tych samych jednostkach co
 * `price_min`/`price_max`/`offers.price` w indeksie (grosze/centy, zgodnie
 * z `price_cents` w Postgresie) — konwersja zł↔grosze dzieje się we
 * froncie (Vue), nie tutaj.
 */
final readonly class SearchCriteria
{
    public const SORTS = ['relevance', 'price_asc', 'price_desc', 'newest'];

    /**
     * @param  array<int, string>  $brands
     */
    public function __construct(
        public string $q,
        public array $brands,
        public ?string $categoryPath,
        public ?int $priceMin,
        public ?int $priceMax,
        public ?bool $inStock,
        public string $sort,
        public ?string $cursor,
        public int $perPage,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $sort = $request->query('sort');

        return new self(
            q: trim((string) $request->query('q', '')),
            brands: array_values(array_filter(
                (array) $request->query('brand', []),
                static fn ($v) => is_string($v) && $v !== '',
            )),
            categoryPath: $request->filled('category') ? (string) $request->query('category') : null,
            priceMin: $request->filled('price_min') ? (int) $request->query('price_min') : null,
            priceMax: $request->filled('price_max') ? (int) $request->query('price_max') : null,
            inStock: $request->has('in_stock')
                ? filter_var($request->query('in_stock'), FILTER_VALIDATE_BOOLEAN)
                : null,
            sort: is_string($sort) && in_array($sort, self::SORTS, true) ? $sort : 'relevance',
            cursor: $request->filled('cursor') ? (string) $request->query('cursor') : null,
            perPage: max(1, min(60, (int) $request->query('per_page', 24))),
        );
    }

    /**
     * Do echa w propsie `filters` (stan filtrów w URL — docs/06, ETAP 7).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'q' => $this->q,
            'brand' => $this->brands,
            'category' => $this->categoryPath,
            'price_min' => $this->priceMin,
            'price_max' => $this->priceMax,
            'in_stock' => $this->inStock,
            'sort' => $this->sort,
            'per_page' => $this->perPage,
        ];
    }
}
