<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import { Spinner } from '@/components/ui/spinner';
import type { SearchResults } from '@/types/search';
import ProductCard from './ProductCard.vue';

/**
 * Infinite scroll na `search_after` (moduł 9, docs/03-SCIEZKA-NAUKI.md).
 *
 * <InfiniteScroll> sam obserwuje koniec listy i, gdy jest blisko, wysyła
 * `router.reload({ only: ['results'], data: { cursor: <nextPage> } })` —
 * kursor bierze z `scrollProps.results.nextPage`, które ustawia
 * SearchController. Serwer odsyła KOLEJNĄ porcję, a Inertia dokleja ją do
 * `results.data` (mergeProps) i zdejmuje duplikaty po `id` (matchPropsOn).
 *
 * - `only-next`     — search_after działa tylko do przodu, "poprzedniej
 *                     strony" nie ma,
 * - `preserve-url`  — kursor to PIT ID + wartości sortu, żyje minutę;
 *                     wkładanie go do URL-a dałoby link, który po chwili
 *                     przestaje działać. URL niesie tylko filtry.
 * - `:buffer`       — zaczynamy ładować ~2 rzędy kart przed końcem listy,
 *                     żeby użytkownik nie widział "dziury".
 */
defineProps<{
    results: SearchResults;
}>();
</script>

<template>
    <div class="flex flex-col gap-4">
        <p class="text-sm text-muted-foreground">
            {{ results.total.value.toLocaleString('pl-PL')
            }}<span v-if="results.total.isLowerBound">+</span>
            wyników
            <span class="text-xs">({{ results.took_ms }} ms)</span>
        </p>

        <InfiniteScroll
            v-if="results.data.length"
            data="results"
            only-next
            preserve-url
            :buffer="600"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <ProductCard
                v-for="product in results.data"
                :key="product.id"
                :product="product"
            />

            <template #next="{ loading, hasMore }">
                <div
                    class="flex justify-center py-6 text-sm text-muted-foreground"
                >
                    <Spinner v-if="loading" class="size-5" />
                    <span v-else-if="!hasMore">To już wszystkie wyniki.</span>
                </div>
            </template>
        </InfiniteScroll>

        <p v-else class="py-12 text-center text-muted-foreground">
            Brak wyników dla wybranych filtrów.
        </p>
    </div>
</template>
