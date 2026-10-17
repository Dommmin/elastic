<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import OrderTimeline from '@/components/orders/OrderTimeline.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatPriceCents } from '@/lib/currency';
import { dashboard } from '@/routes/seller';
import { cancel, index, ship } from '@/routes/seller/orders';
import type {
    OrderTimelineEvent,
    SellerOrderActionName,
    SellerOrderDetails,
} from '@/types';

const props = defineProps<{
    order: SellerOrderDetails;
    actions: SellerOrderActionName[];
    timeline: OrderTimelineEvent[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Portal sprzedawcy', href: dashboard() },
            { title: 'Zamówienia', href: index() },
        ],
    },
});

const page = usePage();
const domainError = computed(() => page.props.errors?.domain);

const shipForm = useForm({ tracking_number: '' });
const cancelForm = useForm({ reason: '' });

function shipNow() {
    shipForm.post(ship(props.order.id).url, { preserveScroll: true });
}

function cancelNow() {
    const amount = formatPriceCents(props.order.total_cents);

    // Tekst zgodny ze specem (H8): zwrot jest automatyczny, stan oferty nie.
    if (
        !window.confirm(
            `Kupujący dostanie zwrot ${amount}. Stan oferty nie wróci automatycznie.`,
        )
    ) {
        return;
    }

    cancelForm.post(cancel(props.order.id).url, { preserveScroll: true });
}

const dateFormat = new Intl.DateTimeFormat('pl-PL', {
    dateStyle: 'short',
    timeStyle: 'short',
});

function refundText(refund: SellerOrderDetails['refunds'][number]): string {
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
                Zamówienie #{{ order.id }} · {{ order.buyer }}
            </h1>
            <OrderStatusBadge
                :status="order.status"
                :label="order.status_label"
            />
        </div>
        <p class="text-sm text-muted-foreground">
            Złożone {{ dateFormat.format(new Date(order.placed_at)) }}
        </p>

        <Alert v-if="domainError" variant="destructive">
            <AlertDescription>{{ domainError }}</AlertDescription>
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

        <form
            v-if="actions.includes('ship')"
            class="flex flex-col gap-2 rounded-md border p-3"
            @submit.prevent="shipNow"
        >
            <Label for="tracking_number">Numer przesyłki</Label>
            <div class="flex flex-wrap gap-2">
                <Input
                    id="tracking_number"
                    v-model="shipForm.tracking_number"
                    class="max-w-xs"
                    maxlength="64"
                    autocomplete="off"
                />
                <Button type="submit" :disabled="shipForm.processing">
                    Oznacz jako wysłane
                </Button>
            </div>
            <InputError :message="shipForm.errors.tracking_number" />
        </form>

        <form
            v-if="actions.includes('cancel_by_seller')"
            class="flex flex-col gap-2 rounded-md border p-3"
            @submit.prevent="cancelNow"
        >
            <Label for="reason">Powód anulowania</Label>
            <div class="flex flex-wrap gap-2">
                <Input
                    id="reason"
                    v-model="cancelForm.reason"
                    class="max-w-md"
                    maxlength="500"
                    autocomplete="off"
                />
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="cancelForm.processing"
                >
                    Anuluj ze zwrotem
                </Button>
            </div>
            <InputError :message="cancelForm.errors.reason" />
        </form>

        <OrderTimeline :events="timeline" />
    </div>
</template>
