<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { Label } from '@/components/ui/label';
import { formatPriceCents } from '@/lib/currency';
import { priceChanges } from '@/routes/panel/moderation';
import { approve, reject } from '@/routes/panel/offers/price-change';
import { edit as productEdit } from '@/routes/panel/products';
import type { PriceChangeQueueItem } from '@/types';

defineProps<{
    items: PriceChangeQueueItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Panel: zmiany cen', href: priceChanges() }],
    },
});

const page = usePage();
// Odmowa agregatu (np. zgłoszenie już rozpatrzone w innej karcie albo
// sprzedawca zmienił zgłoszoną cenę) wraca jako błąd `domain`; brak wersji
// lub ceny w żądaniu (stary widok) — pod własnymi kluczami.
const domainError = computed(
    () =>
        page.props.errors?.domain ??
        page.props.errors?.version ??
        page.props.errors?.expected_price_cents,
);

// Decyzja dotyczy dokładnie tego, co widać w wierszu: zgłoszonej kwoty
// i wersji oferty. Jeśli sprzedawca coś zmienił, serwer odmówi.
function decisionPayload(item: PriceChangeQueueItem) {
    return {
        version: item.version,
        expected_price_cents: item.proposed_price_cents,
    };
}

const visitOptions = { preserveScroll: true } as const;

function formatDate(value: string): string {
    return new Date(value).toLocaleString('pl-PL');
}

function isIncrease(item: PriceChangeQueueItem): boolean {
    return item.proposed_price_cents > item.current_price_cents;
}

// ID wiersza, którego akceptacja jest w toku — blokuje przycisk przed drugim POST-em.
const approvingId = ref<number | null>(null);

function approveChange(item: PriceChangeQueueItem) {
    router.post(approve(item.offer_id).url, decisionPayload(item), {
        ...visitOptions,
        onStart: () => (approvingId.value = item.offer_id),
        onFinish: () => (approvingId.value = null),
    });
}

const rejectOpen = ref(false);
const rejectTarget = ref<PriceChangeQueueItem | null>(null);
const rejectForm = useForm({ reason: '' });

function openReject(item: PriceChangeQueueItem) {
    rejectTarget.value = item;
    rejectForm.reset();
    rejectForm.clearErrors();
    rejectOpen.value = true;
}

function submitReject() {
    const target = rejectTarget.value;

    if (!target) {
        return;
    }

    rejectForm
        .transform((data) => ({ ...data, ...decisionPayload(target) }))
        .post(reject(target.offer_id).url, {
            ...visitOptions,
            onSuccess: () => {
                rejectOpen.value = false;
                rejectForm.reset();
            },
        });
}
</script>

<template>
    <Head title="Panel: zmiany cen" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h1 class="text-xl font-semibold">
                Zmiany cen do akceptacji
                <span class="text-sm font-normal text-muted-foreground">
                    ({{ items.length }})
                </span>
            </h1>
            <p class="text-sm text-muted-foreground">
                Najstarsze zgłoszenia na górze.
            </p>
        </div>

        <Alert v-if="domainError" variant="destructive" role="alert">
            <AlertDescription>{{ domainError }}</AlertDescription>
        </Alert>

        <p v-if="items.length === 0" class="text-sm text-muted-foreground">
            Brak zmian cen do rozpatrzenia.
        </p>

        <div v-else class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="border-b bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Produkt</th>
                        <th class="px-3 py-2 font-medium">Sprzedawca</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Obecna cena
                        </th>
                        <th class="px-3 py-2 text-right font-medium">
                            Nowa cena
                        </th>
                        <th class="px-3 py-2 text-right font-medium">
                            Różnica
                        </th>
                        <th class="px-3 py-2 font-medium">Zgłoszono</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Decyzja
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in items"
                        :key="item.offer_id"
                        class="border-b last:border-0"
                    >
                        <td class="px-3 py-2">
                            <Link
                                :href="productEdit(item.product.id)"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ item.product.name }}
                            </Link>
                            <div class="text-xs text-muted-foreground">
                                oferta #{{ item.offer_id }}
                            </div>
                        </td>
                        <td class="px-3 py-2">{{ item.seller.name }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ formatPriceCents(item.current_price_cents) }}
                        </td>
                        <td
                            class="px-3 py-2 text-right font-medium tabular-nums"
                        >
                            {{ formatPriceCents(item.proposed_price_cents) }}
                        </td>
                        <td
                            class="px-3 py-2 text-right tabular-nums"
                            :class="
                                isIncrease(item)
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            "
                        >
                            <span
                                :aria-label="
                                    isIncrease(item) ? 'podwyżka' : 'obniżka'
                                "
                            >
                                {{ isIncrease(item) ? '↑' : '↓' }}
                            </span>
                            {{ item.change_percent }}%
                        </td>
                        <td class="px-3 py-2 text-muted-foreground">
                            {{ formatDate(item.requested_at) }}
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex justify-end gap-2">
                                <Button
                                    size="sm"
                                    :disabled="approvingId !== null"
                                    @click="approveChange(item)"
                                >
                                    Akceptuj
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="approvingId !== null"
                                    @click="openReject(item)"
                                >
                                    Odrzuć
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Dialog v-model:open="rejectOpen">
            <DialogContent v-if="rejectTarget">
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitReject"
                >
                    <DialogHeader>
                        <DialogTitle>Odrzucić zmianę ceny?</DialogTitle>
                        <DialogDescription>
                            {{ rejectTarget.product.name }} ({{
                                rejectTarget.seller.name
                            }}) — cena zostaje
                            {{
                                formatPriceCents(
                                    rejectTarget.current_price_cents,
                                )
                            }}. Sprzedawca zobaczy powód.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="reason">Powód</Label>
                        <!-- Brak komponentu Textarea w components/ui: natywny element z klasami `Input`. -->
                        <textarea
                            id="reason"
                            v-model="rejectForm.reason"
                            required
                            maxlength="500"
                            rows="4"
                            :aria-invalid="!!rejectForm.errors.reason"
                            class="min-h-20 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none selection:bg-primary selection:text-primary-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                        />
                        <InputError :message="rejectForm.errors.reason" />
                        <InputError :message="domainError" />
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary">
                                Anuluj
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="rejectForm.processing"
                        >
                            Odrzuć
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
