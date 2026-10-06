/**
 * Kształt propsów zwracanych przez App\Services\Search\SearchResult::toArray()
 * i App\Services\ProductSearchService (ETAP 7) — patrz app/Services/Search/.
 */

export interface CheapestOffer {
    offer_id: string;
    seller_id: string;
    seller: string;
    price: number;
    stock: number;
    location: { lat: number; lon: number } | null;
}

export interface ProductHit {
    id: string;
    score: number;
    name: string;
    brand: string | null;
    category: { id: string; path: string } | null;
    price_min: number | null;
    price_max: number | null;
    in_stock: boolean;
    rating_avg: number | null;
    rating_count: number;
    cheapest_offer: CheapestOffer | null;
}

export interface FacetBucket {
    key: string | number;
    from: number | null;
    to: number | null;
    count: number;
}

export interface Facets {
    brand: FacetBucket[];
    category: FacetBucket[];
    price: FacetBucket[];
    in_stock: FacetBucket[];
}

export type SortOption = 'relevance' | 'price_asc' | 'price_desc' | 'newest';

export interface SearchFilters {
    q: string;
    brand: string[];
    category: string | null;
    price_min: number | null;
    price_max: number | null;
    in_stock: boolean | null;
    sort: SortOption;
    per_page: number;
}

/**
 * Prop `results` strony Search — `Inertia::scroll()` (SearchController).
 * Kursor następnej strony NIE jest tutaj: Inertia trzyma go w
 * `page.scrollProps.results.nextPage` i sama przekazuje do <InfiniteScroll>.
 */
export interface SearchResults {
    data: ProductHit[];
    total: { value: number; isLowerBound: boolean };
    took_ms: number;
}

/**
 * Odpowiedź `GET /api/search` (Api\SearchController) — czysty JSON, kursor
 * jawnie w polu, bo klient spoza Inertii nie ma `scrollProps`.
 */
export interface SearchApiResponse {
    items: ProductHit[];
    facets: Facets;
    total: { value: number; isLowerBound: boolean };
    next_cursor: string | null;
    took_ms: number;
    filters: SearchFilters;
}

export interface PriceHistogramBucket {
    price: number;
    count: number;
}

export interface Suggestion {
    id: string;
    name: string;
    brand: string | null;
}
