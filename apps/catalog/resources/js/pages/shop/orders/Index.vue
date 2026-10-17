<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatPriceCents } from '@/lib/currency';
import { search } from '@/routes';
import { index, show } from '@/routes/orders';
import type { OrderListItem } from '@/types';

defineProps<{ orders: OrderListItem[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Moje zamówienia', href: index() }],
    },
});

const dateFormat = new Intl.DateTimeFormat('pl-PL', { dateStyle: 'medium' });
</script>

<template>
    <Head title="Moje zamówienia" />

    <div class="flex flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Moje zamówienia</h1>

        <div v-if="orders.length === 0" class="flex flex-col gap-2">
            <p class="text-sm text-muted-foreground">
                Nie masz jeszcze zamówień.
            </p>
            <Button as-child class="w-fit" variant="outline">
                <Link :href="search()">Szukaj produktów</Link>
            </Button>
        </div>

        <ul v-else class="flex flex-col gap-2">
            <li v-for="order in orders" :key="order.id">
                <Link
                    :href="show(order.id)"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 hover:bg-muted/50"
                >
                    <span class="flex flex-col gap-1">
                        <span class="font-medium">
                            Zamówienie #{{ order.id }} · {{ order.seller }}
                        </span>
                        <span class="text-sm text-muted-foreground">
                            {{ order.first_product }}
                            <template v-if="order.lines_count > 1">
                                i {{ order.lines_count - 1 }} więcej
                            </template>
                            ·
                            {{ dateFormat.format(new Date(order.placed_at)) }}
                        </span>
                    </span>
                    <span class="flex items-center gap-3">
                        <span class="tabular-nums">
                            {{ formatPriceCents(order.total_cents) }}
                        </span>
                        <OrderStatusBadge
                            :status="order.status"
                            :label="order.status_label"
                        />
                    </span>
                </Link>
            </li>
        </ul>
    </div>
</template>
