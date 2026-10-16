<script setup lang="ts">
import { Head, router, usePage, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import OrderTimeline from '@/components/orders/OrderTimeline.vue';
import PaymentCountdown from '@/components/orders/PaymentCountdown.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatPriceCents } from '@/lib/currency';
import { cancel, index, pay, show } from '@/routes/orders';
import type {
    OrderActionName,
    OrderDetails,
    OrderTimelineEvent,
} from '@/types';

const props = defineProps<{
    order: OrderDetails;
    actions: OrderActionName[];
    timeline: OrderTimelineEvent[];
    awaiting_confirmation: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Moje zamówienia', href: index() }],
    },
});

const page = usePage();
const domainError = computed(() => page.props.errors?.domain);
const busy = ref(false);

// Odpytujemy co sekundę tylko wtedy, gdy stan jeszcze się zmieni bez udziału
// kupującego: saga sprawdza dostępność (`placed`) albo czekamy na webhook po
// powrocie z bramki (ograniczone do 30 s, żeby nie bić w serwer bez końca).
const { start, stop } = usePoll(
    1000,
    { only: ['order', 'actions', 'timeline', 'awaiting_confirmation'] },
    { autoStart: false },
);

const confirmationExpired = ref(false);
let confirmationTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.awaiting_confirmation,
    (waiting) => {
        clearTimeout(confirmationTimer);
        confirmationExpired.value = false;

        if (waiting) {
            // Po 30 s zdejmujemy `?payment=returned` z adresu: bez tego odświeżenie
            // strony trzymałoby `awaiting_confirmation` na stałe i „Zapłać” nigdy
            // by nie wróciło (webhook mógł nie dojść albo płatność porzucono).
            confirmationTimer = setTimeout(() => {
                confirmationExpired.value = true;
                router.visit(show(props.order.id), {
                    replace: true,
                    preserveScroll: true,
                });
            }, 30_000);
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => clearTimeout(confirmationTimer));

const polling = computed(
    () =>
        props.order.status === 'placed' ||
        (props.awaiting_confirmation && !confirmationExpired.value),
);

watch(polling, (on) => (on ? start() : stop()), { immediate: true });

function payNow() {
    router.post(
        pay(props.order.id).url,
        {},
        {
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
        },
    );
}

function cancelNow() {
    if (!window.confirm('Na pewno anulować to zamówienie?')) {
        return;
    }

    router.post(
        cancel(props.order.id).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
        },
    );
}

const dateFormat = new Intl.DateTimeFormat('pl-PL', {
    dateStyle: 'short',
    timeStyle: 'short',
});

function refundText(refund: OrderDetails['refunds'][number]): string {
    return `Zwrot ${formatPriceCents(refund.amount_cents)} — ${
        refund.status === 'completed' ? 'zwrócono' : 'w toku'
    }`;
}
</script>

<template>
    <Head :title="`Zamówienie #${order.id}`" />

    <div class="flex max-w-3xl flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">
                Zamówienie #{{ order.id }} · {{ order.seller.name }}
            </h1>
            <OrderStatusBadge
                :status="order.status"
                :label="order.status_label"
            />
        </div>

        <Alert v-if="domainError" variant="destructive">
            <AlertDescription>{{ domainError }}</AlertDescription>
        </Alert>

        <Alert v-if="order.status === 'placed'" role="status">
            <AlertDescription>
                Sprawdzamy dostępność towaru — odświeżam co sekundę.
            </AlertDescription>
        </Alert>

        <Alert v-if="order.rejection_label" variant="destructive">
            <AlertDescription>{{ order.rejection_label }}</AlertDescription>
        </Alert>
        <Alert v-if="order.cancellation_label">
            <AlertDescription>{{ order.cancellation_label }}</AlertDescription>
        </Alert>
        <Alert v-if="order.tracking_number">
            <AlertDescription>
                Numer przesyłki: {{ order.tracking_number }}
            </AlertDescription>
        </Alert>

        <ul class="rounded-md border">
            <li
                v-for="line in order.lines"
                :key="line.offer_id"
                class="flex flex-wrap justify-between gap-2 border-b px-3 py-2 last:border-0"
            >
                <span>{{ line.product_name }} × {{ line.quantity }}</span>
                <span class="tabular-nums">
                    {{
                        formatPriceCents(line.unit_price_cents * line.quantity)
                    }}
                </span>
            </li>
            <li class="flex justify-between bg-muted/50 px-3 py-2 font-medium">
                <span>Razem</span>
                <span class="tabular-nums">
                    {{ formatPriceCents(order.total_cents) }}
                </span>
            </li>
        </ul>

        <p
            v-for="(refund, index) in order.refunds"
            :key="index"
            class="text-sm"
        >
            {{ refundText(refund) }}
        </p>

        <Alert v-if="awaiting_confirmation" role="status">
            <AlertDescription>
                Czekamy na potwierdzenie płatności
            </AlertDescription>
        </Alert>

        <PaymentCountdown
            v-if="order.status === 'awaiting_payment'"
            :seconds="order.seconds_to_deadline"
        />
        <p
            v-if="order.status === 'awaiting_payment'"
            class="text-xs text-muted-foreground"
        >
            Termin: {{ dateFormat.format(new Date(order.payment_deadline)) }}
        </p>

        <div v-if="actions.length > 0" class="flex flex-wrap gap-2">
            <Button
                v-if="actions.includes('pay')"
                :disabled="busy"
                @click="payNow"
            >
                Zapłać
            </Button>
            <Button
                v-if="actions.includes('cancel')"
                variant="outline"
                :disabled="busy"
                @click="cancelNow"
            >
                Anuluj zamówienie
            </Button>
        </div>

        <OrderTimeline :events="timeline" />
    </div>
</template>
