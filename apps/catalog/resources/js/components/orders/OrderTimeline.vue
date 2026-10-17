<script setup lang="ts">
import type { OrderTimelineEvent } from '@/types';

defineProps<{ events: OrderTimelineEvent[] }>();

// Etykiety dla `order_events.event_type` (OrderEventLog::type).
const labels: Record<string, string> = {
    'order.placed': 'Złożono zamówienie',
    'order.line_reserved': 'Zarezerwowano towar',
    'order.awaiting_payment': 'Czeka na płatność',
    'order.rejected': 'Zamówienie odrzucone',
    'order.paid': 'Płatność przyjęta',
    'order.payment_failed': 'Płatność nieudana',
    'order.late_payment_received': 'Wpłata po terminie',
    'order.expired': 'Zamówienie wygasło',
    'order.cancelled': 'Zamówienie anulowane',
    'order.shipped': 'Zamówienie wysłane',
    'order.refund_requested': 'Zlecono zwrot',
    'order.refund_completed': 'Zwrot zakończony',
};

const timeFormat = new Intl.DateTimeFormat('pl-PL', {
    dateStyle: 'short',
    timeStyle: 'medium',
});
</script>

<template>
    <section class="flex flex-col gap-3">
        <h2 class="text-lg font-semibold">Historia zamówienia</h2>
        <p v-if="events.length === 0" class="text-sm text-muted-foreground">
            Brak zdarzeń.
        </p>
        <ol v-else class="flex flex-col gap-2">
            <li
                v-for="event in events"
                :key="event.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-md border p-2 text-sm"
            >
                <span>{{ labels[event.type] ?? event.type }}</span>
                <span class="text-muted-foreground">
                    {{ timeFormat.format(new Date(event.occurred_at)) }}
                </span>
            </li>
        </ol>
    </section>
</template>
