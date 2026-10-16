<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatPriceCents } from '@/lib/currency';
import { search } from '@/routes';
import { checkout, index } from '@/routes/cart';
import { destroy, update } from '@/routes/cart/items';
import { show } from '@/routes/products';
import type { CartGroup, CartLine } from '@/types';

const props = defineProps<{
    groups: CartGroup[];
    orders_count: number;
    total_cents: number;
    has_blockers: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Koszyk', href: index() }],
    },
});

const page = usePage();
const cartError = computed(() => page.props.errors?.cart);
const quantityError = computed(() => page.props.errors?.quantity);
const submitting = ref(false);

const ordersLabel = computed(() => {
    const n = props.orders_count;
    const few = n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20);

    return n === 1
        ? 'zostanie złożone 1 zamówienie'
        : few
          ? `zostaną złożone ${n} zamówienia`
          : `zostanie złożonych ${n} zamówień`;
});

function quantityOptions(line: CartLine): number[] {
    return Array.from(
        { length: Math.max(1, Math.min(10, line.available)) },
        (_, i) => i + 1,
    );
}

function changeQuantity(line: CartLine, quantity: number) {
    router.patch(
        update(line.offer_id).url,
        { quantity },
        { preserveScroll: true },
    );
}

function acceptPrice(line: CartLine) {
    router.patch(
        update(line.offer_id).url,
        { accept_price: true },
        { preserveScroll: true },
    );
}

function remove(line: CartLine) {
    router.delete(destroy(line.offer_id).url, { preserveScroll: true });
}

// Formularz wysyła ceny, które kupujący zaakceptował (`added_price_cents`):
// jeśli oferta w międzyczasie zdrożała, serwer odmówi całego koszyka (H5).
function placeOrders() {
    const lines = props.groups.flatMap((group) =>
        group.lines.map((line) => ({
            offer_id: line.offer_id,
            quantity: line.quantity,
            unit_price_cents: line.added_price_cents,
        })),
    );

    router.post(
        checkout().url,
        { lines },
        {
            preserveScroll: true,
            onStart: () => (submitting.value = true),
            onFinish: () => (submitting.value = false),
        },
    );
}
</script>

<template>
    <Head title="Koszyk" />

    <div class="flex flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Koszyk</h1>

        <Alert v-if="cartError" variant="destructive">
            <AlertDescription>{{ cartError }}</AlertDescription>
        </Alert>

        <div v-if="groups.length === 0" class="flex flex-col gap-2">
            <p class="text-sm text-muted-foreground">Twój koszyk jest pusty.</p>
            <Button as-child class="w-fit" variant="outline">
                <Link :href="search()">Szukaj produktów</Link>
            </Button>
        </div>

        <template v-else>
            <p class="text-sm text-muted-foreground">
                Z tego koszyka {{ ordersLabel }} (po jednym na sprzedawcę).
            </p>

            <section
                v-for="group in groups"
                :key="group.seller.id"
                class="overflow-x-auto rounded-md border"
            >
                <header
                    class="flex items-center justify-between border-b bg-muted/50 px-3 py-2"
                >
                    <h2 class="font-medium">{{ group.seller.name }}</h2>
                    <span class="text-sm tabular-nums">
                        Razem: {{ formatPriceCents(group.subtotal_cents) }}
                    </span>
                </header>

                <ul>
                    <li
                        v-for="line in group.lines"
                        :key="line.offer_id"
                        class="flex flex-wrap items-center justify-between gap-3 border-b px-3 py-3 last:border-0"
                        :class="{
                            'bg-destructive/5': line.status === 'unavailable',
                            'bg-amber-500/10': line.status === 'price_changed',
                        }"
                    >
                        <div class="flex flex-col gap-1">
                            <Link
                                :href="show(line.product_id)"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ line.product_name }}
                            </Link>

                            <p
                                v-if="line.status === 'unavailable'"
                                class="text-sm text-destructive"
                            >
                                <Badge variant="destructive">
                                    Niedostępna
                                </Badge>
                                Ta oferta nie jest już dostępna — usuń ją z
                                koszyka.
                            </p>
                            <p
                                v-else-if="line.status === 'price_changed'"
                                class="text-sm"
                            >
                                <Badge variant="outline">Cena zmieniona</Badge>
                                z
                                <s>{{
                                    formatPriceCents(line.added_price_cents)
                                }}</s>
                                na
                                <strong>{{
                                    formatPriceCents(line.unit_price_cents)
                                }}</strong>
                            </p>
                            <p v-else class="text-sm tabular-nums">
                                {{ formatPriceCents(line.unit_price_cents) }}
                                / szt.
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            <Button
                                v-if="line.status === 'price_changed'"
                                size="sm"
                                variant="outline"
                                @click="acceptPrice(line)"
                            >
                                Akceptuję nową cenę
                            </Button>

                            <select
                                v-if="line.status !== 'unavailable'"
                                :value="line.quantity"
                                :aria-label="`Ilość: ${line.product_name}`"
                                class="h-9 rounded-md border bg-background px-2 text-sm"
                                @change="
                                    changeQuantity(
                                        line,
                                        Number(
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        ),
                                    )
                                "
                            >
                                <option
                                    v-for="n in quantityOptions(line)"
                                    :key="n"
                                    :value="n"
                                >
                                    {{ n }}
                                </option>
                            </select>

                            <Button
                                size="icon"
                                variant="ghost"
                                :aria-label="`Usuń: ${line.product_name}`"
                                @click="remove(line)"
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>

            <InputError :message="quantityError" />

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-lg font-semibold tabular-nums">
                    Do zapłaty: {{ formatPriceCents(total_cents) }}
                </p>
                <Button
                    :disabled="has_blockers || submitting"
                    @click="placeOrders"
                >
                    Złóż zamówienia
                </Button>
            </div>
            <p v-if="has_blockers" class="text-sm text-muted-foreground">
                Popraw oznaczone pozycje (zaakceptuj nową cenę albo usuń
                niedostępne), aby złożyć zamówienia.
            </p>
        </template>
    </div>
</template>
