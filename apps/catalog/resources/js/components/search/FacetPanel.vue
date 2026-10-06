<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Slider } from '@/components/ui/slider';
import {
    centsFromZloty,
    formatPriceCents,
    zlotyFromCents,
} from '@/lib/currency';
import type { Facets, SearchFilters } from '@/types/search';

/**
 * Facety jako "filtered aggregations" (App\Services\ProductSearchService::
 * buildFacetAggs) — liczniki tutaj już uwzględniają WSZYSTKIE inne aktywne
 * filtry oprócz własnego wymiaru, więc np. liczba przy marce "Adidas" nie
 * spada do zera po zaznaczeniu "Nike". `change` emituje TYLKO zmieniony
 * wymiar — rodzic (Search.vue) łączy go z resztą i robi partial reload
 * `only: ['results', 'facets']`.
 */
const props = defineProps<{
    facets: Facets;
    filters: SearchFilters;
}>();

const emit = defineEmits<{
    change: [partial: Partial<SearchFilters>];
}>();

const SLIDER_MAX_ZLOTY = 10_000;

function toggleBrand(brand: string) {
    const next = props.filters.brand.includes(brand)
        ? props.filters.brand.filter((b) => b !== brand)
        : [...props.filters.brand, brand];

    emit('change', { brand: next });
}

function toggleCategory(path: string) {
    emit('change', { category: props.filters.category === path ? null : path });
}

function onInStockChange(checked: boolean) {
    emit('change', { in_stock: checked ? true : null });
}

// Slider trzyma stan LOKALNIE podczas przeciągania (jeden `change` na
// puszczenie kciuka, nie na każdą klatkę) — inaczej każde 1 zł ruchu
// suwaka wysłałoby osobne zapytanie do ES.
const priceRange = ref<[number, number]>([
    props.filters.price_min !== null
        ? zlotyFromCents(props.filters.price_min)
        : 0,
    props.filters.price_max !== null
        ? zlotyFromCents(props.filters.price_max)
        : SLIDER_MAX_ZLOTY,
]);

watch(
    () => [props.filters.price_min, props.filters.price_max],
    ([min, max]) => {
        priceRange.value = [
            min !== null ? zlotyFromCents(min) : 0,
            max !== null ? zlotyFromCents(max) : SLIDER_MAX_ZLOTY,
        ];
    },
);

function commitPriceRange(value: number[] | undefined) {
    if (!value) {
        return;
    }

    const [min, max] = value;

    emit('change', {
        price_min: min > 0 ? centsFromZloty(min) : null,
        price_max: max < SLIDER_MAX_ZLOTY ? centsFromZloty(max) : null,
    });
}

const hasActiveFilters = computed(
    () =>
        props.filters.brand.length > 0 ||
        props.filters.category !== null ||
        props.filters.price_min !== null ||
        props.filters.price_max !== null ||
        props.filters.in_stock !== null,
);

function clearAll() {
    emit('change', {
        brand: [],
        category: null,
        price_min: null,
        price_max: null,
        in_stock: null,
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <div v-if="hasActiveFilters" class="flex justify-end">
            <button
                type="button"
                class="text-xs text-muted-foreground underline-offset-2 hover:underline"
                @click="clearAll"
            >
                Wyczyść filtry
            </button>
        </div>

        <div>
            <h3 class="mb-3 text-sm font-semibold">Cena</h3>
            <Slider
                :model-value="priceRange"
                :min="0"
                :max="SLIDER_MAX_ZLOTY"
                :step="10"
                class="mb-2"
                @update:model-value="
                    (v) => (priceRange = v as [number, number])
                "
                @value-commit="commitPriceRange"
            />
            <p class="text-xs text-muted-foreground">
                {{ formatPriceCents(centsFromZloty(priceRange[0])) }} –
                {{ formatPriceCents(centsFromZloty(priceRange[1]))
                }}{{ priceRange[1] >= SLIDER_MAX_ZLOTY ? '+' : '' }}
            </p>
        </div>

        <Separator />

        <div>
            <div class="flex items-center gap-2">
                <Checkbox
                    id="facet-in-stock"
                    :model-value="filters.in_stock === true"
                    @update:model-value="(v) => onInStockChange(!!v)"
                />
                <Label for="facet-in-stock" class="text-sm font-normal"
                    >Tylko dostępne</Label
                >
            </div>
        </div>

        <Separator v-if="facets.brand.length" />

        <div v-if="facets.brand.length">
            <h3 class="mb-3 text-sm font-semibold">Marka</h3>
            <div class="flex max-h-64 flex-col gap-2 overflow-y-auto">
                <div
                    v-for="bucket in facets.brand"
                    :key="bucket.key"
                    class="flex items-center gap-2"
                >
                    <Checkbox
                        :id="`facet-brand-${bucket.key}`"
                        :model-value="
                            filters.brand.includes(String(bucket.key))
                        "
                        @update:model-value="
                            () => toggleBrand(String(bucket.key))
                        "
                    />
                    <Label
                        :for="`facet-brand-${bucket.key}`"
                        class="flex-1 text-sm font-normal"
                    >
                        {{ bucket.key }}
                    </Label>
                    <span class="text-xs text-muted-foreground">{{
                        bucket.count
                    }}</span>
                </div>
            </div>
        </div>

        <Separator v-if="facets.category.length" />

        <div v-if="facets.category.length">
            <h3 class="mb-3 text-sm font-semibold">Kategoria</h3>
            <div class="flex flex-col gap-2">
                <div
                    v-for="bucket in facets.category"
                    :key="bucket.key"
                    class="flex items-center gap-2"
                >
                    <Checkbox
                        :id="`facet-category-${bucket.key}`"
                        :model-value="filters.category === String(bucket.key)"
                        @update:model-value="
                            () => toggleCategory(String(bucket.key))
                        "
                    />
                    <Label
                        :for="`facet-category-${bucket.key}`"
                        class="flex-1 text-sm font-normal"
                    >
                        {{ bucket.key }}
                    </Label>
                    <span class="text-xs text-muted-foreground">{{
                        bucket.count
                    }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
