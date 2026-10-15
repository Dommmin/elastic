<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed } from 'vue';
import BlockedBanner from '@/components/seller/BlockedBanner.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { offerFilterLabels } from '@/lib/offer';
import { dashboard } from '@/routes/seller';
import { create, index } from '@/routes/seller/offers';
import type { OfferFilter, SellerDashboardStats } from '@/types';

defineProps<{ stats: SellerDashboardStats }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Portal sprzedawcy', href: dashboard() }],
    },
});

const page = usePage();
const seller = computed(() => page.props.auth.seller);
const isBlocked = computed(() => seller.value?.status === 'blocked');

// Kolejność kafelków: najpierw to, co wymaga reakcji sprzedawcy.
const tiles: { filter: OfferFilter; hint: string }[] = [
    { filter: 'sold_out', hint: 'opublikowane bez towaru' },
    { filter: 'pending_price', hint: 'czekają na moderatora' },
    { filter: 'draft', hint: 'jeszcze nie w sprzedaży' },
    { filter: 'published', hint: 'w tym wyprzedane' },
    { filter: 'suspended', hint: 'niewidoczne dla kupujących' },
    { filter: 'withdrawn', hint: 'zdjęte ze sprzedaży' },
];
</script>

<template>
    <Head title="Portal sprzedawcy" />

    <div class="flex flex-col gap-4 p-4">
        <BlockedBanner
            v-if="isBlocked"
            :reason="seller?.status_reason ?? null"
        />

        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">{{ seller?.name }}</h1>
            <div class="flex gap-2">
                <Button as-child variant="outline">
                    <Link :href="index()">Wszystkie oferty</Link>
                </Button>
                <Button v-if="!isBlocked" as-child>
                    <Link :href="create()"><Plus /> Nowa oferta</Link>
                </Button>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="tile in tiles"
                :key="tile.filter"
                :href="index({ query: { status: tile.filter } })"
                class="rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <Card class="h-full transition-colors hover:bg-muted/50">
                    <CardHeader>
                        <CardDescription>
                            {{ offerFilterLabels[tile.filter] }}
                        </CardDescription>
                        <CardTitle class="text-3xl tabular-nums">
                            {{ stats[tile.filter] }}
                        </CardTitle>
                        <CardDescription class="text-xs">
                            {{ tile.hint }}
                        </CardDescription>
                    </CardHeader>
                </Card>
            </Link>
        </div>
    </div>
</template>
