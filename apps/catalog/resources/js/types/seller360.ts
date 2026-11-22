/**
 * Props ekranu konsultanta (ETAP 13): App\Http\Controllers\Panel\Seller360Controller
 * i App\Seller360\Seller360Card. Sekcja bez danych ma `present: false` i `missing_label`.
 */
export type Seller360SectionKey =
    'profile' | 'offers' | 'sales' | 'billing' | 'complaints' | 'registry';

export type Seller360SectionStatus = 'ok' | 'stale' | 'error';

export type Seller360Meta = {
    version: number;
    source_ts: string | null;
    updated_at: string | null;
    status: Seller360SectionStatus;
    error: string | null;
};

export type Seller360Section = {
    key: Seller360SectionKey;
    source: string;
    present: boolean;
    missing_label: string;
    data: Record<string, unknown>;
    meta: Seller360Meta | null;
};

export type Seller360Card = {
    id: number;
    nip: string | null;
    region: string | null;
    synthetic: boolean;
    name: string | null;
    doc_updated_at: string | null;
    sections: Seller360Section[];
};

export type Seller360Row = {
    id: number;
    name: string | null;
    nip: string | null;
    city: string | null;
    region: string | null;
    gmv_30d_cents: number | null;
    conversion_drop: number | null;
    overdue_cents: number | null;
    complaints_open: number | null;
    vat_status: string | null;
    courier: string | null;
    not_ok_sections: Seller360SectionKey[];
};

export type Seller360Filters = {
    q: string;
    region: string;
    vat_status: string;
    overdue: boolean;
    min_drop: number | null;
    complaint_phrase: string;
    courier: string;
    sort: '' | 'gmv';
};
