<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed } from 'vue';
import BlockedBanner from '@/components/seller/BlockedBanner.vue';
import OfferStatusBadge from '@/components/seller/OfferStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatPriceCents } from '@/lib/currency';
import { offerFilterLabels } from '@/lib/offer';
import { dashboard } from '@/routes/seller';
import { create, index, show } from '@/routes/seller/offers';
import type { OfferFilter, SellerOfferRow } from '@/types';

type PaginatorLink = { url: string | null; label: string; active: boolean };

defineProps<{
    offers: {
        data: SellerOfferRow[];
        links: PaginatorLink[];
        total: number;
    };
    filters: { status: OfferFilter | null };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Portal sprzedawcy', href: dashboard() },
            { title: 'Oferty', href: index() },
        ],
    },
});

const page = usePage();
const seller = computed(() => page.props.auth.seller);
const isBlocked = computed(() => seller.value?.status === 'blocked');

const statusFilters: { value: OfferFilter | null; label: string }[] = [
    { value: null, label: 'Wszystkie' },
    ...(Object.keys(offerFilterLabels) as OfferFilter[]).map((value) => ({
        value,
        label: offerFilterLabels[value],
    })),
];

function filterUrl(status: OfferFilter | null): string {
    return index(status ? { query: { status } } : undefined).url;
}

function pageLabel(label: string): string {
    if (label.includes('Previous')) {
        return '‹ Poprzednia';
    }

    if (label.includes('Next')) {
        return 'Następna ›';
    }

    return label;
}
</script>

<template>
    <Head title="Moje oferty" />

    <div class="flex flex-col gap-4 p-4">
        <BlockedBanner
            v-if="isBlocked"
            :reason="seller?.status_reason ?? null"
        />

        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">
                Moje oferty
                <span class="text-sm font-normal text-muted-foreground">
                    ({{ offers.total }})
                </span>
            </h1>
            <Button v-if="!isBlocked" as-child>
                <Link :href="create()"><Plus /> Nowa oferta</Link>
            </Button>
        </div>

        <nav class="flex flex-wrap gap-1" aria-label="Filtr statusu">
            <Link
                v-for="filter in statusFilters"
                :key="filter.label"
                :href="filterUrl(filter.value)"
                preserve-scroll
                class="rounded border px-3 py-1 text-sm"
                :class="
                    filters.status === filter.value
                        ? 'bg-primary text-primary-foreground'
                        : ''
                "
            >
                {{ filter.label }}
            </Link>
        </nav>

        <p
            v-if="offers.data.length === 0"
            class="text-sm text-muted-foreground"
        >
            Brak ofert w tym widoku.
        </p>

        <div v-else class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="border-b bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Produkt</th>
                        <th class="px-3 py-2 text-right font-medium">Cena</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Dostępne
                        </th>
                        <th class="px-3 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="offer in offers.data"
                        :key="offer.id"
                        class="border-b last:border-0 hover:bg-muted/30"
                    >
                        <td class="px-3 py-2">
                            <Link
                                :href="show(offer.id)"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ offer.product.name }}
                            </Link>
                            <span class="ml-1 text-xs text-muted-foreground">
                                #{{ offer.id }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ formatPriceCents(offer.price_cents) }}
                            <div
                                v-if="offer.pending_price_cents !== null"
                                class="text-xs text-muted-foreground"
                            >
                                →
                                {{
                                    formatPriceCents(offer.pending_price_cents)
                                }}
                            </div>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ offer.available }}
                        </td>
                        <td class="px-3 py-2">
                            <OfferStatusBadge
                                :status="offer.status"
                                :is-sold-out="offer.is_sold_out"
                                :has-pending-price="
                                    offer.pending_price_cents !== null
                                "
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="offers.links.length > 3"
            class="flex flex-wrap gap-1"
            aria-label="Strony"
        >
            <template v-for="link in offers.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded border px-3 py-1 text-sm"
                    :class="
                        link.active ? 'bg-primary text-primary-foreground' : ''
                    "
                >
                    {{ pageLabel(link.label) }}
                </Link>
                <span
                    v-else
                    class="rounded border px-3 py-1 text-sm text-muted-foreground"
                >
                    {{ pageLabel(link.label) }}
                </span>
            </template>
        </nav>
    </div>
</template>
