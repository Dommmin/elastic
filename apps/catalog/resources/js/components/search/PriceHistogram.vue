<script setup lang="ts">
import { computed } from 'vue';
import { formatPriceCents } from '@/lib/currency';
import type { PriceHistogramBucket } from '@/types/search';

/**
 * Prosty słupkowy histogram cen — konsument `Inertia::defer()` propsa
 * `priceHistogram` (App\Http\Controllers\SearchController). Celowo bez
 * biblioteki wykresów: to kilka `<div>`-ów ze skalowaną wysokością, projekt
 * nie ma jeszcze zależności do wykresów i to jedyne miejsce, gdzie się
 * przydaje.
 */
const props = defineProps<{
    buckets: PriceHistogramBucket[];
}>();

const maxCount = computed(() =>
    Math.max(1, ...props.buckets.map((b) => b.count)),
);
</script>

<template>
    <div v-if="buckets.length" class="flex h-24 items-end gap-0.5">
        <div
            v-for="bucket in buckets"
            :key="bucket.price"
            class="group relative flex-1 rounded-t bg-primary/30 transition-colors hover:bg-primary/60"
            :style="{
                height: `${Math.max(4, (bucket.count / maxCount) * 100)}%`,
            }"
        >
            <span
                class="pointer-events-none absolute -top-6 left-1/2 -translate-x-1/2 rounded bg-popover px-1.5 py-0.5 text-xs whitespace-nowrap text-popover-foreground opacity-0 shadow-sm group-hover:opacity-100"
            >
                {{ formatPriceCents(bucket.price) }} · {{ bucket.count }}
            </span>
        </div>
    </div>
    <p v-else class="text-sm text-muted-foreground">
        Brak danych do histogramu.
    </p>
</template>
