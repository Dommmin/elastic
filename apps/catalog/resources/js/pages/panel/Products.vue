<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { formatPriceCents } from '@/lib/currency';
import { edit, index } from '@/routes/panel/products';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Panel: produkty', href: index() }],
    },
});

type ProductRow = {
    id: number;
    name: string;
    brand: string | null;
    category_label: string;
    active_offers_count: number;
    min_price_cents: number | null;
};

type PaginatorLink = { url: string | null; label: string; active: boolean };

const props = defineProps<{
    products: {
        data: ProductRow[];
        links: PaginatorLink[];
        total: number;
    };
    filters: { q: string };
}>();

// Laravel zwraca etykiety po angielsku z encjami HTML ("&laquo; Previous"),
// więc tłumaczymy je zamiast wstrzykiwać przez v-html.
function pageLabel(label: string): string {
    if (label.includes('Previous')) {
        return '‹ Poprzednia';
    }

    if (label.includes('Next')) {
        return 'Następna ›';
    }

    return label;
}

const q = ref(props.filters.q);
let timer: ReturnType<typeof setTimeout> | undefined;

// Debounce 300 ms: nie pytamy serwera o każdy znak. `preserveState` zachowuje
// fokus w polu, a `replace` nie zaśmieca historii przeglądarki.
watch(q, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(index().url, value ? { q: value } : {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});
</script>

<template>
    <Head title="Panel: produkty" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex items-center justify-between gap-4">
            <Input
                v-model="q"
                type="search"
                placeholder="Szukaj po nazwie lub ID"
                class="max-w-sm"
                aria-label="Szukaj produktów"
            />
            <span class="text-sm text-muted-foreground">
                {{ products.total }} produktów
            </span>
        </div>

        <div class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="border-b bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">ID</th>
                        <th class="px-3 py-2 font-medium">Nazwa</th>
                        <th class="px-3 py-2 font-medium">Marka</th>
                        <th class="px-3 py-2 font-medium">Kategoria</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Aktywne oferty
                        </th>
                        <th class="px-3 py-2 text-right font-medium">
                            Najniższa cena
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="product in products.data"
                        :key="product.id"
                        class="border-b last:border-0"
                    >
                        <td class="px-3 py-2 text-muted-foreground">
                            {{ product.id }}
                        </td>
                        <td class="px-3 py-2">
                            <Link
                                :href="edit(product.id).url"
                                class="font-medium hover:underline"
                            >
                                {{ product.name }}
                            </Link>
                        </td>
                        <td class="px-3 py-2">{{ product.brand }}</td>
                        <td class="px-3 py-2">{{ product.category_label }}</td>
                        <td class="px-3 py-2 text-right">
                            {{ product.active_offers_count }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            {{
                                product.min_price_cents === null
                                    ? '—'
                                    : formatPriceCents(product.min_price_cents)
                            }}
                        </td>
                    </tr>
                    <tr v-if="products.data.length === 0">
                        <td
                            colspan="6"
                            class="px-3 py-6 text-center text-muted-foreground"
                        >
                            Brak produktów.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav class="flex flex-wrap gap-1" aria-label="Strony">
            <template v-for="(link, i) in products.links" :key="i">
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
