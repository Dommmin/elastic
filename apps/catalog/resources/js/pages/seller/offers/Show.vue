<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import BlockedBanner from '@/components/seller/BlockedBanner.vue';
import OfferStatusBadge from '@/components/seller/OfferStatusBadge.vue';
import OfferTimeline from '@/components/seller/OfferTimeline.vue';
import PriceChangeForm from '@/components/seller/PriceChangeForm.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatPriceCents } from '@/lib/currency';
import { conditionLabels } from '@/lib/offer';
import { dashboard } from '@/routes/seller';
import {
    details,
    index,
    publish,
    stock,
    withdraw,
} from '@/routes/seller/offers';
import { destroy as cancelPendingPrice } from '@/routes/seller/offers/pending-price';
import type {
    OfferAction,
    OfferTimelineEvent,
    PriceGuard,
    SellerOfferDetails,
} from '@/types';

const props = defineProps<{
    offer: SellerOfferDetails;
    allowed_actions: OfferAction[];
    price_guard: PriceGuard;
    timeline: OfferTimelineEvent[];
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
// Błąd reguły domeny (limit, konflikt wersji, blokada) wraca pod kluczem
// `domain`; brak wersji w żądaniu (stary widok) — pod `version`.
const domainError = computed(
    () => page.props.errors?.domain ?? page.props.errors?.version,
);

// Przyciski powstają WYŁĄCZNIE z `allowed_actions` (domena), nie ze statusu.
const can = (action: OfferAction) => props.allowed_actions.includes(action);

const visitOptions = { preserveScroll: true } as const;
const busy = ref(false);

// Każda akcja odsyła wersję oferty, którą widać na stronie (`offer.version`).
// Jeśli w innej karcie coś się zmieniło, serwer odmówi zamiast nadpisać —
// komunikat „odśwież stronę” pokaże się w alercie `domainError`.
const withVersion = <T extends object>(data: T) => ({
    ...data,
    version: props.offer.version,
});

function post(url: string, method: 'post' | 'delete' = 'post') {
    router.visit(url, {
        method,
        data: withVersion({}),
        ...visitOptions,
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    });
}

const stockForm = useForm({ stock: props.offer.stock });
const detailsForm = useForm({
    condition: props.offer.condition,
    shipping_days: props.offer.shipping_days,
});

// Po każdym zapisie serwer odsyła świeżą ofertę — formularze startują od niej.
// Partial reload osi czasu nie podmienia `offer`, więc nie kasuje wpisywanych wartości.
watch(
    () => props.offer,
    (offer) => {
        stockForm.defaults({ stock: offer.stock }).reset();
        detailsForm
            .defaults({
                condition: offer.condition,
                shipping_days: offer.shipping_days,
            })
            .reset();
    },
);

function submitStock() {
    stockForm
        .transform(withVersion)
        .put(stock(props.offer.id).url, visitOptions);
}

function submitDetails() {
    detailsForm
        .transform(withVersion)
        .put(details(props.offer.id).url, visitOptions);
}

const withdrawOpen = ref(false);

const withdrawing = ref(false);

function confirmWithdraw() {
    router.post(withdraw(props.offer.id).url, withVersion({}), {
        ...visitOptions,
        // Bez blokady drugi klik wysyłał drugi POST, który po udanym
        // wycofaniu kończył się mylącym „Operacja niedozwolona…”.
        onStart: () => (withdrawing.value = true),
        onFinish: () => {
            withdrawing.value = false;
            withdrawOpen.value = false;
        },
    });
}

const timeFormat = new Intl.DateTimeFormat('pl-PL', {
    dateStyle: 'short',
    timeStyle: 'short',
});
</script>

<template>
    <Head :title="`Oferta: ${offer.product.name}`" />

    <div class="flex max-w-3xl flex-col gap-6 p-4">
        <BlockedBanner
            v-if="seller?.status === 'blocked'"
            :reason="seller.status_reason"
        />

        <header class="flex flex-col gap-1">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold">{{ offer.product.name }}</h1>
                <OfferStatusBadge
                    :status="offer.status"
                    :is-sold-out="offer.is_sold_out"
                    :has-pending-price="offer.pending_price !== null"
                />
            </div>
            <p class="text-sm text-muted-foreground">
                Oferta #{{ offer.id }} · wersja {{ offer.version }} ·
                {{
                    offer.is_listed
                        ? 'widoczna w wyszukiwarce'
                        : 'niewidoczna w wyszukiwarce'
                }}
            </p>
        </header>

        <Alert v-if="domainError" variant="destructive" role="alert">
            <AlertDescription>{{ domainError }}</AlertDescription>
        </Alert>

        <Alert v-if="offer.suspension" variant="destructive">
            <AlertTitle>Oferta zawieszona</AlertTitle>
            <AlertDescription>
                <template v-if="offer.suspension.kind === 'seller_blocked'">
                    Z powodu blokady konta sprzedawcy. Wróci do sprzedaży po
                    odblokowaniu.
                </template>
                <template v-else>
                    Przez moderatora<template v-if="offer.suspension.note"
                        >: {{ offer.suspension.note }}</template
                    >.
                </template>
            </AlertDescription>
        </Alert>

        <Alert v-if="offer.pending_price">
            <AlertTitle>Zmiana ceny czeka na moderatora</AlertTitle>
            <AlertDescription class="flex flex-col gap-2">
                <p>
                    {{ formatPriceCents(offer.price_cents) }} →
                    {{ formatPriceCents(offer.pending_price.price_cents) }},
                    zgłoszono
                    {{
                        timeFormat.format(
                            new Date(offer.pending_price.requested_at),
                        )
                    }}. Do decyzji obowiązuje obecna cena.
                </p>
                <div v-if="can('cancel_pending_price_change')">
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="busy"
                        @click="
                            post(cancelPendingPrice(offer.id).url, 'delete')
                        "
                    >
                        Anuluj zgłoszenie
                    </Button>
                </div>
            </AlertDescription>
        </Alert>

        <dl
            class="grid grid-cols-2 gap-4 rounded-md border p-4 text-sm sm:grid-cols-4"
        >
            <div>
                <dt class="text-muted-foreground">Cena</dt>
                <dd class="text-lg font-semibold tabular-nums">
                    {{ formatPriceCents(offer.price_cents) }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Stan magazynowy</dt>
                <dd class="text-lg font-semibold tabular-nums">
                    {{ offer.stock }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Stan egzemplarza</dt>
                <dd class="text-lg font-semibold">
                    {{ conditionLabels[offer.condition] }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Wysyłka</dt>
                <dd class="text-lg font-semibold tabular-nums">
                    {{ offer.shipping_days }} dni
                </dd>
            </div>
        </dl>

        <div
            v-if="can('publish') || can('withdraw')"
            class="flex flex-wrap gap-2"
        >
            <Button
                v-if="can('publish')"
                :disabled="busy"
                @click="post(publish(offer.id).url)"
            >
                Opublikuj
            </Button>
            <Button
                v-if="can('withdraw')"
                variant="outline"
                :disabled="busy"
                @click="withdrawOpen = true"
            >
                Wycofaj ofertę
            </Button>
        </div>
        <p
            v-if="
                offer.status === 'draft' &&
                !can('publish') &&
                can('change_stock')
            "
            class="text-sm text-muted-foreground"
        >
            Aby opublikować, ustaw stan magazynowy większy od zera.
        </p>

        <section
            v-if="can('change_price')"
            class="flex flex-col gap-2 rounded-md border p-4"
        >
            <h2 class="font-semibold">Cena</h2>
            <PriceChangeForm
                :offer-id="offer.id"
                :version="offer.version"
                :current-cents="offer.price_cents"
                :guard="price_guard"
            />
        </section>

        <section
            v-if="can('change_stock')"
            class="flex flex-col gap-2 rounded-md border p-4"
        >
            <h2 class="font-semibold">Stan magazynowy</h2>
            <form class="flex flex-col gap-2" @submit.prevent="submitStock">
                <Label for="stock" class="sr-only">Liczba sztuk</Label>
                <div class="flex gap-2">
                    <Input
                        id="stock"
                        v-model.number="stockForm.stock"
                        type="number"
                        min="0"
                        max="100000"
                        class="max-w-32"
                        required
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="stockForm.processing"
                    >
                        Zapisz stan
                    </Button>
                </div>
                <InputError :message="stockForm.errors.stock" />
                <p class="text-xs text-muted-foreground">
                    0 sztuk zdejmuje opublikowaną ofertę z wyszukiwarki
                    (wyprzedana).
                </p>
            </form>
        </section>

        <section
            v-if="can('change_details')"
            class="flex flex-col gap-2 rounded-md border p-4"
        >
            <h2 class="font-semibold">Szczegóły</h2>
            <form
                class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                @submit.prevent="submitDetails"
            >
                <div class="grid gap-2">
                    <Label for="condition">Stan egzemplarza</Label>
                    <Select v-model="detailsForm.condition">
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
                    <InputError :message="detailsForm.errors.condition" />
                </div>
                <div class="grid gap-2">
                    <Label for="shipping_days">Wysyłka (dni)</Label>
                    <Input
                        id="shipping_days"
                        v-model.number="detailsForm.shipping_days"
                        type="number"
                        min="1"
                        max="30"
                        required
                    />
                    <InputError :message="detailsForm.errors.shipping_days" />
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="detailsForm.processing"
                >
                    Zapisz szczegóły
                </Button>
            </form>
        </section>

        <OfferTimeline :events="timeline" />

        <Dialog v-model:open="withdrawOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Wycofać ofertę?</DialogTitle>
                    <DialogDescription>
                        Oferta zniknie z wyszukiwarki i nie da się jej
                        przywrócić. Na ten produkt możesz potem założyć nową.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline">Anuluj</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        :disabled="withdrawing"
                        @click="confirmWithdraw"
                    >
                        Wycofaj
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
