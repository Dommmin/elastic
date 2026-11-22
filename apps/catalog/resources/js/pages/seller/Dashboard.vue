<script setup lang="ts">
import { Deferred, Head, Link, usePage } from '@inertiajs/vue3';
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
import { formatPriceCents } from '@/lib/currency';
import { offerFilterLabels } from '@/lib/offer';
import { relativeTime } from '@/lib/relativeTime';
import { dashboard } from '@/routes/seller';
import { create, index } from '@/routes/seller/offers';
import { index as ordersIndex } from '@/routes/seller/orders';
import type { OfferFilter, SellerDashboardStats, SellerStats } from '@/types';

defineProps<{
    stats: SellerDashboardStats;
    // Deferred: undefined do czasu dociągnięcia, null gdy brak dokumentu albo ES niedostępny.
    seller360?: SellerStats | null;
}>();

const percent = (value: number | undefined) =>
    value === undefined ? '—' : `${(value * 100).toFixed(1)}%`;

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
                <Button as-child variant="outline">
                    <Link :href="ordersIndex()">Zamówienia</Link>
                </Button>
                <Button v-if="!isBlocked" as-child>
                    <Link :href="create()"><Plus /> Nowa oferta</Link>
                </Button>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                :href="ordersIndex()"
                class="rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <Card class="h-full transition-colors hover:bg-muted/50">
                    <CardHeader>
                        <CardDescription>Do wysłania</CardDescription>
                        <CardTitle class="text-3xl tabular-nums">
                            {{ stats.orders_to_ship }}
                        </CardTitle>
                        <CardDescription class="text-xs">
                            opłacone zamówienia czekają na Ciebie
                        </CardDescription>
                    </CardHeader>
                </Card>
            </Link>
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

        <section class="flex flex-col gap-3" aria-labelledby="seller360-title">
            <h2 id="seller360-title" class="text-lg font-semibold">
                Twoje statystyki
            </h2>

            <Deferred data="seller360">
                <template #fallback>
                    <p class="text-sm text-muted-foreground">
                        Ładuję statystyki…
                    </p>
                </template>

                <p
                    v-if="!seller360"
                    class="text-sm text-muted-foreground"
                    data-test="seller360-unavailable"
                >
                    Statystyki chwilowo niedostępne
                </p>

                <div
                    v-else
                    class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                    data-test="seller360"
                >
                    <Card>
                        <CardHeader>
                            <CardDescription>Oferty aktywne</CardDescription>
                            <CardTitle class="text-3xl tabular-nums">
                                {{ seller360.offers?.active ?? '—' }}
                            </CardTitle>
                            <CardDescription class="text-xs">
                                z {{ seller360.offers?.total ?? '—' }} · dane
                                {{ relativeTime(seller360.freshness.offers) }}
                            </CardDescription>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>
                                Sprzedaż z 30 dni
                            </CardDescription>
                            <CardTitle class="text-3xl tabular-nums">
                                {{
                                    seller360.sales?.gmv_30d_cents === undefined
                                        ? '—'
                                        : formatPriceCents(
                                              seller360.sales.gmv_30d_cents,
                                          )
                                }}
                            </CardTitle>
                            <CardDescription class="text-xs">
                                {{ seller360.sales?.orders_30d ?? '—' }}
                                zamówień · dane
                                {{ relativeTime(seller360.freshness.sales) }}
                            </CardDescription>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>Konwersja koszyka</CardDescription>
                            <CardTitle class="text-3xl tabular-nums">
                                {{ percent(seller360.sales?.conversion_30d) }}
                            </CardTitle>
                            <CardDescription class="text-xs">
                                poprzednio
                                {{
                                    percent(
                                        seller360.sales?.conversion_prev_30d,
                                    )
                                }}
                            </CardDescription>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>
                                Terminowość kuriera
                            </CardDescription>
                            <CardTitle class="text-3xl tabular-nums">
                                {{
                                    percent(
                                        seller360.courier_on_time_rate ??
                                            undefined,
                                    )
                                }}
                            </CardTitle>
                            <CardDescription class="text-xs">
                                ocena z rejestru · dane
                                {{ relativeTime(seller360.freshness.courier) }}
                            </CardDescription>
                        </CardHeader>
                    </Card>
                </div>
            </Deferred>
        </section>
    </div>
</template>
