<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Deferred, Head, router } from '@inertiajs/vue3';
import FacetPanel from '@/components/search/FacetPanel.vue';
import PriceHistogram from '@/components/search/PriceHistogram.vue';
import SearchBar from '@/components/search/SearchBar.vue';
import SearchResults from '@/components/search/SearchResults.vue';
import SortSelector from '@/components/search/SortSelector.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { search } from '@/routes';
import type {
    Facets,
    PriceHistogramBucket,
    SearchFilters,
    SearchResults as SearchResultsType,
    SortOption,
} from '@/types/search';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Wyszukiwarka', href: search() }],
    },
});

const props = defineProps<{
    results: SearchResultsType;
    facets: Facets;
    filters: SearchFilters;
    priceHistogram?: PriceHistogramBucket[];
}>();

/**
 * Jedno miejsce budujące query string dla WSZYSTKICH zmian filtrów na tej
 * stronie (tekst, facety, sort) — SearchCriteria::fromRequest
 * (app/Services/Search/SearchCriteria.php) to lustrzane odbicie tego
 * kształtu po stronie PHP. Kursora tu nie ma: dokłada go sam
 * <InfiniteScroll> przy przewijaniu (SearchResults.vue).
 */
function toQuery(filters: SearchFilters): Record<string, FormDataConvertible> {
    const query: Record<string, FormDataConvertible> = {};

    if (filters.q) {
        query.q = filters.q;
    }

    if (filters.brand.length) {
        query.brand = filters.brand;
    }

    if (filters.category) {
        query.category = filters.category;
    }

    if (filters.price_min !== null) {
        query.price_min = filters.price_min;
    }

    if (filters.price_max !== null) {
        query.price_max = filters.price_max;
    }

    if (filters.in_stock) {
        query.in_stock = 1;
    }

    if (filters.sort !== 'relevance') {
        query.sort = filters.sort;
    }

    if (filters.per_page !== 24) {
        query.per_page = filters.per_page;
    }

    return query;
}

/**
 * `only` domyślnie ogranicza się do results+facets — DoD ETAP 7: kliknięcie
 * facetu NIE ma prawa odpytać ES o `priceHistogram` (patrz SearchController).
 * Zmiana frazy (`refreshHistogram: true`) jest jedynym wyjątkiem: nowy tekst
 * realnie zmienia rozkład cen, więc histogram jawnie prosimy o odświeżenie
 * w TYM SAMYM żądaniu zamiast polegać na automatyce `Inertia::defer()`.
 *
 * `replace` tylko dla frazy: SearchBar wysyła ją z debounce przy pisaniu, więc
 * nowy wpis w historii na każde "lap", "lapt", "lapto" zamieniłby przycisk
 * "wstecz" w cofanie po literce. Facety i sort to pojedyncze, świadome
 * kliknięcia — każde dostaje WŁASNY wpis, więc "wstecz" przywraca poprzedni
 * zestaw filtrów (DoD ETAP 7: stan w URL + obsługa przycisku "wstecz").
 *
 * `reset: ['results']` jest OBOWIĄZKOWE: `results.data` to prop doklejany
 * (Inertia::scroll), więc bez resetu wyniki nowego filtra zostałyby
 * DOPISANE na koniec wyników starego, a <InfiniteScroll> trzymałby kursor
 * starego zapytania.
 */
function applyFilters(
    partial: Partial<SearchFilters>,
    options: { refreshHistogram?: boolean; replaceHistory?: boolean } = {},
) {
    const next: SearchFilters = { ...props.filters, ...partial };
    const only = options.refreshHistogram
        ? ['results', 'facets', 'priceHistogram', 'filters']
        : ['results', 'facets', 'filters'];

    router.get(search.url(), toQuery(next), {
        preserveState: true,
        preserveScroll: true,
        replace: options.replaceHistory ?? false,
        only,
        reset: ['results'],
    });
}

function onSearchText(q: string) {
    applyFilters({ q }, { refreshHistogram: true, replaceHistory: true });
}

function onFacetChange(partial: Partial<SearchFilters>) {
    applyFilters(partial);
}

function onSortChange(sort: SortOption) {
    applyFilters({ sort });
}
</script>

<template>
    <Head title="Wyszukiwarka" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <SearchBar :model-value="filters.q" @search="onSearchText" />
            <SortSelector
                :model-value="filters.sort"
                @update:model-value="onSortChange"
            />
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[260px_1fr]">
            <aside class="lg:sticky lg:top-4 lg:self-start">
                <FacetPanel
                    :facets="facets"
                    :filters="filters"
                    @change="onFacetChange"
                />
            </aside>

            <div class="flex flex-col gap-6">
                <Deferred data="priceHistogram">
                    <template #fallback>
                        <Skeleton class="h-24 w-full" />
                    </template>
                    <PriceHistogram :buckets="priceHistogram ?? []" />
                </Deferred>

                <SearchResults :results="results" />
            </div>
        </div>
    </div>
</template>
