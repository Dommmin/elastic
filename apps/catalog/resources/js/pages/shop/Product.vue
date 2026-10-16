<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatPriceCents } from '@/lib/currency';
import { conditionLabels } from '@/lib/offer';
import { login, search } from '@/routes';
import { store } from '@/routes/cart/items';
import type { ShopOffer, ShopProduct } from '@/types';

const props = defineProps<{
    product: ShopProduct;
    offers: ShopOffer[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Wyszukiwarka', href: search() }],
    },
});

const page = usePage();
const isGuest = computed(() => !page.props.auth.user);

// Wybrana ilość per oferta; domyślnie 1.
const quantities = reactive<Record<number, number>>({});
const adding = reactive<Record<number, boolean>>({});
// Błędy walidacji dotyczą ostatnio dodawanej oferty (`offer_id` w odpowiedzi).
const errors = computed(() => page.props.errors ?? {});
const failedOfferId = reactive<{ id: number | null }>({ id: null });

function quantityOf(offer: ShopOffer): number {
    return quantities[offer.id] ?? 1;
}

function quantityOptions(offer: ShopOffer): number[] {
    return Array.from({ length: offer.max_quantity }, (_, i) => i + 1);
}

function addToCart(offer: ShopOffer) {
    failedOfferId.id = offer.id;
    adding[offer.id] = true;

    router.post(
        store().url,
        { offer_id: offer.id, quantity: quantityOf(offer) },
        {
            preserveScroll: true,
            onFinish: () => {
                adding[offer.id] = false;
            },
        },
    );
}

function errorFor(offer: ShopOffer, field: 'quantity' | 'offer_id') {
    return failedOfferId.id === offer.id ? errors.value[field] : undefined;
}
</script>

<template>
    <Head :title="props.product.name" />

    <div class="flex flex-col gap-4 p-4">
        <div>
            <p v-if="product.brand" class="text-sm text-muted-foreground">
                {{ product.brand }}
            </p>
            <h1 class="text-2xl font-semibold">{{ product.name }}</h1>
            <p
                v-if="product.description"
                class="mt-2 max-w-prose text-sm text-muted-foreground"
            >
                {{ product.description }}
            </p>
        </div>

        <h2 class="text-lg font-semibold">Oferty</h2>

        <p v-if="offers.length === 0" class="text-sm text-muted-foreground">
            Ten produkt nie ma teraz żadnej dostępnej oferty.
        </p>

        <div v-else class="flex flex-col gap-3">
            <Card v-for="offer in offers" :key="offer.id">
                <CardContent
                    class="flex flex-wrap items-center justify-between gap-4 p-4"
                >
                    <div class="flex flex-col gap-1">
                        <p class="font-medium">{{ offer.seller.name }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ conditionLabels[offer.condition] }} · wysyłka do
                            {{ offer.shipping_days }} dni · dostępne:
                            {{ offer.available }} szt.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <p class="text-lg font-bold tabular-nums">
                            {{ formatPriceCents(offer.price_cents) }}
                        </p>

                        <Badge v-if="offer.is_own" variant="outline">
                            Twoja oferta
                        </Badge>

                        <Button v-else-if="isGuest" as-child>
                            <Link :href="login()">
                                Zaloguj się, aby kupić
                            </Link>
                        </Button>

                        <form
                            v-else
                            class="flex items-center gap-2"
                            @submit.prevent="addToCart(offer)"
                        >
                            <select
                                :value="quantityOf(offer)"
                                :aria-label="`Ilość: ${offer.seller.name}`"
                                class="h-9 rounded-md border bg-background px-2 text-sm"
                                @change="
                                    quantities[offer.id] = Number(
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    )
                                "
                            >
                                <option
                                    v-for="n in quantityOptions(offer)"
                                    :key="n"
                                    :value="n"
                                >
                                    {{ n }}
                                </option>
                            </select>
                            <Button type="submit" :disabled="adding[offer.id]">
                                Dodaj do koszyka
                            </Button>
                        </form>
                    </div>

                    <div class="w-full">
                        <InputError :message="errorFor(offer, 'quantity')" />
                        <InputError :message="errorFor(offer, 'offer_id')" />
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
