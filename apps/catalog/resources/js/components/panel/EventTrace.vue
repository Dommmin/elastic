<script setup lang="ts">
import { usePoll } from '@inertiajs/vue3';
import { watch } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { formatPriceCents } from '@/lib/currency';
import type { Trace, TraceComparison, TraceComparisonField } from '@/types';

const props = defineProps<{ trace: Trace }>();

// Partial reload tylko `trace` i tylko dopóki któreś zdarzenie nie dotarło
// do ES. Gdy ES nieosiągalny, `pending` jest false, więc polling nie bije
// co sekundę w martwy klaster.
const { start, stop } = usePoll(
    1000,
    { only: ['trace'] },
    { autoStart: false },
);

watch(
    () => props.trace.pending,
    (pending) => (pending ? start() : stop()),
    { immediate: true },
);

const fieldLabels: Record<TraceComparisonField, string> = {
    name: 'Nazwa',
    category: 'Kategoria',
    price_min: 'Najniższa cena',
    in_stock: 'Dostępny',
};

const timeFormat = new Intl.DateTimeFormat('pl-PL', {
    dateStyle: 'short',
    timeStyle: 'medium',
});

function formatValue(
    field: TraceComparisonField,
    value: TraceComparison['postgres'],
): string {
    if (value === null) {
        return '—';
    }

    if (field === 'price_min') {
        return formatPriceCents(Number(value));
    }

    if (field === 'in_stock') {
        return value ? 'tak' : 'nie';
    }

    return String(value);
}

function stepClass(state: boolean | null): string {
    if (state === null) {
        return 'border-dashed text-muted-foreground';
    }

    return state
        ? 'border-transparent bg-primary text-primary-foreground'
        : 'text-muted-foreground';
}
</script>

<template>
    <section class="flex flex-col gap-3">
        <div class="flex items-baseline justify-between gap-2">
            <h2 class="text-lg font-semibold">Droga zdarzenia</h2>
            <span
                v-if="trace.pending"
                class="text-xs text-muted-foreground"
                role="status"
            >
                czekam na ES, odświeżam co 1 s
            </span>
        </div>

        <Alert v-if="!trace.es_available" variant="destructive">
            <AlertDescription>
                ES nieosiągalny: nie wiadomo, czy zdarzenia dotarły do
                wyszukiwarki.
            </AlertDescription>
        </Alert>

        <p
            v-if="trace.events.length === 0"
            class="text-sm text-muted-foreground"
        >
            Brak zdarzeń dla tego produktu i jego ofert.
        </p>
        <ol v-else class="flex flex-col gap-2">
            <li
                v-for="event in trace.events"
                :key="event.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-md border p-2 text-sm"
            >
                <span>
                    <span class="font-mono">{{ event.type }}</span>
                    <span class="ml-2 text-muted-foreground">
                        #{{ event.id }},
                        {{ timeFormat.format(new Date(event.occurred_at)) }}
                    </span>
                </span>
                <span class="flex gap-1 text-xs">
                    <span
                        class="rounded-full border px-2 py-0.5"
                        :class="stepClass(true)"
                    >
                        zapisane
                    </span>
                    <span
                        class="rounded-full border px-2 py-0.5"
                        :class="stepClass(event.published)"
                    >
                        opublikowane
                    </span>
                    <span
                        class="rounded-full border px-2 py-0.5"
                        :class="stepClass(event.in_es)"
                        :title="
                            event.in_es === null ? 'ES nieosiągalny' : undefined
                        "
                    >
                        w ES{{ event.in_es === null ? ' ?' : '' }}
                    </span>
                </span>
            </li>
        </ol>

        <table class="w-full text-sm">
            <caption class="mb-1 text-left text-xs text-muted-foreground">
                Postgres a dokument w ES
            </caption>
            <thead>
                <tr class="text-left text-xs text-muted-foreground">
                    <th class="py-1 font-medium">Pole</th>
                    <th class="py-1 font-medium">Postgres</th>
                    <th class="py-1 font-medium">ES</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in trace.comparison"
                    :key="row.field"
                    class="border-t"
                    :class="{
                        'bg-destructive/10 text-destructive': row.differs,
                    }"
                >
                    <td class="py-1 pr-2">
                        {{ fieldLabels[row.field] }}
                        <span v-if="row.differs" class="text-xs"
                            >(różni się)</span
                        >
                    </td>
                    <td class="py-1 pr-2">
                        {{ formatValue(row.field, row.postgres) }}
                    </td>
                    <td class="py-1">{{ formatValue(row.field, row.es) }}</td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
