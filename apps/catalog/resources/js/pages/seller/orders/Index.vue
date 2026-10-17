<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatPriceCents } from '@/lib/currency';
import { dashboard } from '@/routes/seller';
import { index, show } from '@/routes/seller/orders';
import type { SellerOrderListItem, SellerOrderTab } from '@/types';

defineProps<{ tab: SellerOrderTab; orders: SellerOrderListItem[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Portal sprzedawcy', href: dashboard() },
            { title: 'Zamówienia', href: index() },
        ],
    },
});

const tabs: { value: SellerOrderTab; label: string }[] = [
    { value: 'to_ship', label: 'Do wysłania' },
    { value: 'all', label: 'Wszystkie' },
];

const dateFormat = new Intl.DateTimeFormat('pl-PL', { dateStyle: 'medium' });
</script>

<template>
    <Head title="Zamówienia" />

    <div class="flex flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Zamówienia</h1>

        <nav class="flex gap-2" aria-label="Filtr zamówień">
            <Button
                v-for="item in tabs"
                :key="item.value"
                as-child
                :variant="tab === item.value ? 'default' : 'outline'"
                size="sm"
            >
                <Link
                    :href="index({ query: { tab: item.value } })"
                    :aria-current="tab === item.value ? 'page' : undefined"
                >
                    {{ item.label }}
                </Link>
            </Button>
        </nav>

        <p v-if="orders.length === 0" class="text-sm text-muted-foreground">
            {{
                tab === 'to_ship'
                    ? 'Nic nie czeka na wysyłkę.'
                    : 'Nie masz jeszcze zamówień.'
            }}
        </p>

        <ul v-else class="flex flex-col gap-2">
            <li v-for="order in orders" :key="order.id">
                <Link
                    :href="show(order.id)"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 hover:bg-muted/50"
                >
                    <span class="flex flex-col gap-1">
                        <span class="font-medium">
                            Zamówienie #{{ order.id }} · {{ order.buyer }}
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
