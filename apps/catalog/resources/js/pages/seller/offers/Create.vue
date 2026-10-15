<script setup lang="ts">
import { Head, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import BlockedBanner from '@/components/seller/BlockedBanner.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { conditionLabels } from '@/lib/offer';
import { dashboard } from '@/routes/seller';
import { create, index, store } from '@/routes/seller/offers';
import { lookup } from '@/routes/seller/products';
import type { OfferCondition, ProductLookupHit } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Portal sprzedawcy', href: dashboard() },
            { title: 'Oferty', href: index() },
            { title: 'Nowa oferta', href: create() },
        ],
    },
});

const page = usePage();
const seller = computed(() => page.props.auth.seller);
// Link „Nowa oferta” jest ukryty dla zablokowanego, ale adres da się wpisać:
// wtedy baner zamiast formularza, który i tak skończyłby się odmową domeny.
const isBlocked = computed(() => seller.value?.status === 'blocked');
// Np. „Masz już aktywną ofertę na ten produkt” z `LiveOfferAlreadyExists`.
const domainError = computed(() => page.props.errors?.domain);

const MIN_QUERY_LENGTH = 2;
const DEBOUNCE_MS = 250;

// Wyszukiwanie produktu: JSON przez `useHttp` (bez wizyty Inertia), z debounce,
// żeby nie strzelać zapytaniem przy każdym znaku.
const search = useHttp<{ q: string }, ProductLookupHit[]>({ q: '' });
const hits = ref<ProductLookupHit[]>([]);
const selected = ref<ProductLookupHit | null>(null);
let debounceTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => search.q,
    (query) => {
        clearTimeout(debounceTimer);

        if (query.trim().length < MIN_QUERY_LENGTH) {
            search.cancel();
            hits.value = [];

            return;
        }

        debounceTimer = setTimeout(() => {
            // Starsza, wolniejsza odpowiedź nie może nadpisać wyników nowszego zapytania.
            search.cancel();
            search.get(lookup().url, {
                onSuccess: (response) => {
                    hits.value = response;
                },
            });
        }, DEBOUNCE_MS);
    },
);

onBeforeUnmount(() => clearTimeout(debounceTimer));

const form = useForm({
    product_id: null as number | null,
    price: '',
    stock: 1,
    condition: 'new' as OfferCondition,
    shipping_days: 2,
});

function choose(product: ProductLookupHit) {
    selected.value = product;
    form.product_id = product.id;
    form.clearErrors('product_id');
    hits.value = [];
    search.q = '';
}

function clearSelection() {
    selected.value = null;
    form.product_id = null;
}

function submit() {
    form.post(store().url);
}
</script>

<template>
    <Head title="Nowa oferta" />

    <div class="flex max-w-xl flex-col gap-4 p-4">
        <BlockedBanner
            v-if="isBlocked"
            :reason="seller?.status_reason ?? null"
        />

        <h1 class="text-xl font-semibold">Nowa oferta</h1>
        <p v-if="isBlocked" class="text-sm text-muted-foreground">
            Zablokowane konto nie może wystawiać nowych ofert.
        </p>
        <p v-else class="text-sm text-muted-foreground">
            Oferta powstaje jako szkic — opublikujesz ją na następnym ekranie.
        </p>

        <Alert v-if="domainError" variant="destructive" role="alert">
            <AlertDescription>{{ domainError }}</AlertDescription>
        </Alert>

        <form
            v-if="!isBlocked"
            class="flex flex-col gap-4"
            @submit.prevent="submit"
        >
            <div class="grid gap-2">
                <Label for="product-search">Produkt</Label>

                <div
                    v-if="selected"
                    class="flex items-center justify-between gap-2 rounded-md border p-2 text-sm"
                >
                    <span>
                        <span class="font-medium">{{ selected.name }}</span>
                        <span class="ml-2 text-muted-foreground">
                            {{ selected.brand ?? 'bez marki' }}
                            <template v-if="selected.ean">
                                · EAN {{ selected.ean }}
                            </template>
                        </span>
                    </span>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label="Zmień produkt"
                        @click="clearSelection"
                    >
                        <X />
                    </Button>
                </div>

                <template v-else>
                    <Input
                        id="product-search"
                        v-model="search.q"
                        type="search"
                        autocomplete="off"
                        placeholder="Nazwa albo EAN (min. 2 znaki)"
                    />
                    <p
                        v-if="search.processing"
                        class="text-xs text-muted-foreground"
                        role="status"
                    >
                        Szukam…
                    </p>
                    <p
                        v-else-if="
                            search.q.trim().length >= MIN_QUERY_LENGTH &&
                            search.wasSuccessful &&
                            hits.length === 0
                        "
                        class="text-xs text-muted-foreground"
                    >
                        Brak pasujących produktów (pomijamy te, na które masz
                        już ofertę).
                    </p>
                    <ul
                        v-if="hits.length > 0"
                        class="flex flex-col divide-y rounded-md border"
                    >
                        <li v-for="hit in hits" :key="hit.id">
                            <button
                                type="button"
                                class="flex w-full flex-col items-start px-3 py-2 text-left text-sm hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none"
                                @click="choose(hit)"
                            >
                                <span class="font-medium">{{ hit.name }}</span>
                                <span class="text-xs text-muted-foreground">
                                    {{ hit.brand ?? 'bez marki' }}
                                    <template v-if="hit.ean">
                                        · EAN {{ hit.ean }}
                                    </template>
                                </span>
                            </button>
                        </li>
                    </ul>
                </template>
                <InputError :message="form.errors.product_id" />
            </div>

            <div class="grid gap-2">
                <Label for="price">Cena (zł)</Label>
                <Input
                    id="price"
                    v-model="form.price"
                    inputmode="decimal"
                    autocomplete="off"
                    placeholder="np. 1299,99"
                    required
                />
                <InputError :message="form.errors.price" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="stock">Stan magazynowy</Label>
                    <Input
                        id="stock"
                        v-model.number="form.stock"
                        type="number"
                        min="0"
                        max="100000"
                        required
                    />
                    <InputError :message="form.errors.stock" />
                </div>

                <div class="grid gap-2">
                    <Label for="condition">Stan egzemplarza</Label>
                    <Select v-model="form.condition">
                        <SelectTrigger id="condition" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="(label, value) in conditionLabels"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.condition" />
                </div>

                <div class="grid gap-2">
                    <Label for="shipping_days">Wysyłka (dni)</Label>
                    <Input
                        id="shipping_days"
                        v-model.number="form.shipping_days"
                        type="number"
                        min="1"
                        max="30"
                        required
                    />
                    <InputError :message="form.errors.shipping_days" />
                </div>
            </div>

            <div>
                <Button
                    type="submit"
                    :disabled="form.processing || form.product_id === null"
                >
                    Zapisz szkic
                </Button>
            </div>
        </form>
    </div>
</template>
