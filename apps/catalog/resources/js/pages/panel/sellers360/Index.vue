<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatPriceCents } from '@/lib/currency';
import { index, show } from '@/routes/panel/sellers360';
import type { Seller360Filters, Seller360Row } from '@/types';

const props = defineProps<{
    sellers: Seller360Row[];
    total: number;
    took: number | null;
    page: number;
    perPage: number;
    es_available: boolean;
    query_error: number | null;
    filters: Seller360Filters;
    dodPreset: {
        region: string;
        min_drop: number;
        overdue: boolean;
        complaint_phrase: string;
        sort: 'gmv';
    };
    options: { regions: string[]; couriers: string[] };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Panel: Seller 360', href: index() }],
    },
});

// Lokalna kopia filtrów: formularz wysyła je dopiero przyciskiem „Szukaj”.
const form = reactive({
    ...props.filters,
    min_drop:
        props.filters.min_drop === null ? '' : String(props.filters.min_drop),
});

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

function query(page = 1): Record<string, string> {
    const q: Record<string, string> = {};

    for (const key of [
        'q',
        'region',
        'vat_status',
        'complaint_phrase',
        'courier',
        'sort',
    ] as const) {
        if (form[key]) {
            q[key] = String(form[key]);
        }
    }

    if (form.overdue) {
        q.overdue = '1';
    }

    if (form.min_drop !== '') {
        q.min_drop = form.min_drop;
    }

    if (page > 1) {
        q.page = String(page);
    }

    return q;
}

function search(page = 1) {
    router.get(index({ query: query(page) }).url, {}, { preserveScroll: true });
}

// Gotowe zapytanie z DoD ETAPU 13: ustawia filtry i od razu szuka.
function applyDod() {
    Object.assign(form, {
        q: '',
        vat_status: '',
        courier: '',
        region: props.dodPreset.region,
        min_drop: String(props.dodPreset.min_drop),
        overdue: props.dodPreset.overdue,
        complaint_phrase: props.dodPreset.complaint_phrase,
        sort: props.dodPreset.sort,
    });
    search();
}

function reset() {
    router.get(index().url);
}

const lastPage = computed(() =>
    Math.max(1, Math.ceil(props.total / props.perPage)),
);

function percent(value: number | null): string {
    return value === null ? '—' : `${(value * 100).toFixed(1)}%`;
}

function cents(value: number | null): string {
    return value === null ? '—' : formatPriceCents(value);
}
</script>

<template>
    <Head title="Panel: Seller 360" />

    <div class="flex flex-col gap-6 p-4">
        <Alert v-if="!es_available" variant="destructive" role="alert">
            <AlertDescription>
                Elasticsearch jest niedostępny, wyniki chwilowo niewidoczne.
            </AlertDescription>
        </Alert>
        <Alert v-else-if="query_error" variant="destructive" role="alert">
            <AlertDescription>
                Elasticsearch odrzucił zapytanie (HTTP {{ query_error }}). Zmień
                filtry i spróbuj ponownie.
            </AlertDescription>
        </Alert>

        <form
            class="grid gap-4 rounded-md border p-4 md:grid-cols-4"
            @submit.prevent="search()"
        >
            <div class="grid gap-2 md:col-span-2">
                <Label for="q"
                    >Szukaj (nazwa, NIP, miasto, treść reklamacji)</Label
                >
                <Input
                    id="q"
                    v-model="form.q"
                    placeholder="np. Kasztan albo 1500815906"
                />
            </div>
            <div class="grid gap-2">
                <Label for="region">Województwo</Label>
                <select id="region" v-model="form.region" :class="selectClass">
                    <option value="">dowolne</option>
                    <option v-for="r in options.regions" :key="r" :value="r">
                        {{ r }}
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="vat_status">Status VAT</Label>
                <select
                    id="vat_status"
                    v-model="form.vat_status"
                    :class="selectClass"
                >
                    <option value="">dowolny</option>
                    <option value="active">czynny</option>
                    <option value="inactive">nieczynny</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="min_drop">Spadek konwersji co najmniej (0–1)</Label>
                <Input
                    id="min_drop"
                    v-model="form.min_drop"
                    type="number"
                    step="0.05"
                    min="0"
                    max="1"
                />
            </div>
            <div class="grid gap-2">
                <Label for="complaint_phrase">Fraza w reklamacjach</Label>
                <Input
                    id="complaint_phrase"
                    v-model="form.complaint_phrase"
                    placeholder="np. uszkodzon*"
                />
            </div>
            <div class="grid gap-2">
                <Label for="courier">Kurier</Label>
                <select
                    id="courier"
                    v-model="form.courier"
                    :class="selectClass"
                >
                    <option value="">dowolny</option>
                    <option v-for="c in options.couriers" :key="c" :value="c">
                        {{ c }}
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="sort">Sortowanie</Label>
                <select id="sort" v-model="form.sort" :class="selectClass">
                    <option value="">trafność / ID</option>
                    <option value="gmv">sprzedaż 30 dni (malejąco)</option>
                </select>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.overdue" type="checkbox" />
                Tylko z zaległością
            </label>
            <div
                class="flex flex-wrap items-end gap-2 md:col-span-3 md:justify-end"
            >
                <Button type="button" variant="outline" @click="reset"
                    >Wyczyść</Button
                >
                <Button type="button" variant="secondary" @click="applyDod">
                    Zapytanie z DoD
                </Button>
                <Button type="submit">Szukaj</Button>
            </div>
        </form>

        <section class="flex flex-col gap-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">Sprzedawcy</h2>
                <span class="text-sm text-muted-foreground">
                    {{ total }} wyników<template v-if="took !== null"
                        >, ES {{ took }} ms</template
                    >
                </span>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2 font-medium">ID</th>
                            <th class="px-3 py-2 font-medium">Nazwa</th>
                            <th class="px-3 py-2 font-medium">NIP</th>
                            <th class="px-3 py-2 font-medium">
                                Miasto / region
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Sprzedaż 30 dni
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Spadek konwersji
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Zaległość
                            </th>
                            <th class="px-3 py-2 font-medium">Świeżość</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="seller in sellers"
                            :key="seller.id"
                            class="border-b last:border-0"
                        >
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ seller.id }}
                            </td>
                            <td class="px-3 py-2 font-medium">
                                <Link
                                    :href="show(seller.id)"
                                    class="underline-offset-4 hover:underline"
                                >
                                    {{ seller.name ?? 'brak danych z catalog' }}
                                </Link>
                            </td>
                            <td class="px-3 py-2">{{ seller.nip ?? '—' }}</td>
                            <td class="px-3 py-2">
                                {{ seller.city ?? '—' }}
                                <span class="text-muted-foreground"
                                    >/ {{ seller.region ?? '—' }}</span
                                >
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ cents(seller.gmv_30d_cents) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ percent(seller.conversion_drop) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{ cents(seller.overdue_cents) }}
                            </td>
                            <td class="px-3 py-2">
                                <Badge
                                    v-for="key in seller.not_ok_sections"
                                    :key="key"
                                    variant="destructive"
                                    class="mr-1"
                                >
                                    {{ key }}
                                </Badge>
                                <span
                                    v-if="seller.not_ok_sections.length === 0"
                                    class="text-muted-foreground"
                                    >ok</span
                                >
                            </td>
                        </tr>
                        <tr v-if="sellers.length === 0">
                            <td
                                colspan="8"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                Brak sprzedawców dla tych filtrów.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="lastPage > 1"
                class="flex items-center gap-2 text-sm"
                aria-label="Strony"
            >
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="page <= 1"
                    @click="search(page - 1)"
                >
                    ‹ Poprzednia
                </Button>
                <span class="text-muted-foreground"
                    >{{ page }} / {{ lastPage }}</span
                >
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="page >= lastPage"
                    @click="search(page + 1)"
                >
                    Następna ›
                </Button>
            </nav>
        </section>
    </div>
</template>
