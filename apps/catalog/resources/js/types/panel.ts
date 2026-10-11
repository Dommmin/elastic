/**
 * Prop `trace` strony edycji produktu (App\Services\Panel\EventTrace).
 * `in_es` jest `null`, gdy ES nieosiągalny: krok „w ES” jest wtedy nieznany.
 */
export type TraceEvent = {
    id: number;
    type: string;
    occurred_at: string;
    published: boolean;
    in_es: boolean | null;
};

export type TraceComparisonField =
    'name' | 'category' | 'price_min' | 'in_stock';

export type TraceComparison = {
    field: TraceComparisonField;
    postgres: string | number | boolean | null;
    es: string | number | boolean | null;
    differs: boolean;
};

export type Trace = {
    es_available: boolean;
    events: TraceEvent[];
    comparison: TraceComparison[];
    pending: boolean;
};
