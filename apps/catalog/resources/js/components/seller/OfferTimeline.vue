<script setup lang="ts">
import { usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { timelineEventLabels } from '@/lib/offer';
import type { OfferTimelineEvent } from '@/types';

const props = defineProps<{ events: OfferTimelineEvent[] }>();

// Wzorem panelowego EventTrace: partial reload samego `timeline`, i to tylko
// dopóki najnowsze zdarzenie czeka w outboxie. Gdy relay je wypchnie,
// polling staje, zamiast bić w serwer bez końca.
const waiting = computed(() => props.events[0]?.published === false);

const { start, stop } = usePoll(
    2000,
    { only: ['timeline'] },
    { autoStart: false },
);

watch(waiting, (isWaiting) => (isWaiting ? start() : stop()), {
    immediate: true,
});

const timeFormat = new Intl.DateTimeFormat('pl-PL', {
    dateStyle: 'short',
    timeStyle: 'medium',
});
</script>

<template>
    <section class="flex flex-col gap-3">
        <div class="flex items-baseline justify-between gap-2">
            <h2 class="text-lg font-semibold">Historia zdarzeń</h2>
            <span
                v-if="waiting"
                class="text-xs text-muted-foreground"
                role="status"
            >
                czekam na publikację, odświeżam co 2 s
            </span>
        </div>

        <p v-if="events.length === 0" class="text-sm text-muted-foreground">
            Brak zdarzeń.
        </p>
        <ol v-else class="flex flex-col gap-2">
            <li
                v-for="event in events"
                :key="event.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-md border p-2 text-sm"
            >
                <span>
                    {{ timelineEventLabels[event.type] ?? event.type }}
                    <span class="ml-2 text-muted-foreground">
                        {{ timeFormat.format(new Date(event.occurred_at)) }}
                    </span>
                </span>
                <span
                    class="rounded-full border px-2 py-0.5 text-xs"
                    :class="
                        event.published
                            ? 'border-transparent bg-primary text-primary-foreground'
                            : 'text-muted-foreground'
                    "
                    :title="event.type"
                >
                    {{
                        event.published
                            ? 'wysłane do wyszukiwarki'
                            : 'w kolejce'
                    }}
                </span>
            </li>
        </ol>
    </section>
</template>
