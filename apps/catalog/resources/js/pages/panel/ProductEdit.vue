<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import EventTrace from '@/components/panel/EventTrace.vue';
import OfferRow from '@/components/panel/OfferRow.vue';
import type { PanelOffer } from '@/components/panel/OfferRow.vue';
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
import { index, update } from '@/routes/panel/products';
import type { Trace } from '@/types';

type Product = {
    id: number;
    name: string;
    brand_id: number;
    category_id: number;
    version: number;
};

const props = defineProps<{
    product: Product;
    brands: { id: number; name: string }[];
    categories: { id: number; label: string }[];
    offers: PanelOffer[];
    trace: Trace;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Panel: produkty', href: index() }],
    },
});

const page = usePage();
// Flash z `Inertia::flash` (tekst „Zapisano…” / „Bez zmian”) znika przy kolejnej nawigacji.
const status = computed(() => page.flash.status as string | undefined);
// Odmowa agregatu przy akcji moderatora (np. oferta zmieniona od
// wyrenderowania strony) wraca jako błąd `domain`; brak wersji w żądaniu — jako `version`.
const domainError = computed(
    () => page.props.errors?.domain ?? page.props.errors?.version,
);

const form = useForm({
    name: props.product.name,
    brand_id: String(props.product.brand_id),
    category_id: String(props.product.category_id),
});

function submit() {
    form.put(update(props.product.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Edycja: ${product.name}`" />

    <div class="flex max-w-xl flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">
            Produkt #{{ product.id }}
            <span class="text-sm font-normal text-muted-foreground">
                wersja {{ product.version }}
            </span>
        </h1>

        <Alert v-if="domainError" variant="destructive" role="alert">
            <AlertDescription>{{ domainError }}</AlertDescription>
        </Alert>

        <Alert v-if="status" role="status">
            <AlertDescription>{{ status }}</AlertDescription>
        </Alert>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="name">Nazwa</Label>
                <Input id="name" v-model="form.name" required />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="brand_id">Marka</Label>
                <Select v-model="form.brand_id">
                    <SelectTrigger id="brand_id" class="w-full">
                        <SelectValue placeholder="Wybierz markę" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="brand in brands"
                            :key="brand.id"
                            :value="String(brand.id)"
                        >
                            {{ brand.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.brand_id" />
            </div>

            <div class="grid gap-2">
                <Label for="category_id">Kategoria</Label>
                <Select v-model="form.category_id">
                    <SelectTrigger id="category_id" class="w-full">
                        <SelectValue placeholder="Wybierz kategorię" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="category in categories"
                            :key="category.id"
                            :value="String(category.id)"
                        >
                            {{ category.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.category_id" />
            </div>

            <div>
                <Button type="submit" :disabled="form.processing">
                    Zapisz
                </Button>
            </div>
        </form>

        <section class="flex flex-col gap-2">
            <h2 class="text-lg font-semibold">Oferty</h2>
            <p v-if="offers.length === 0" class="text-sm text-muted-foreground">
                Produkt nie ma ofert.
            </p>
            <ul v-else class="flex flex-col gap-2">
                <OfferRow
                    v-for="offer in offers"
                    :key="offer.id"
                    :offer="offer"
                />
            </ul>
        </section>

        <EventTrace :trace="trace" />
    </div>
</template>
