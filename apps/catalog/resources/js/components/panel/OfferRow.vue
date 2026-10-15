<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import OfferStatusBadge from '@/components/seller/OfferStatusBadge.vue';
import { Badge } from '@/components/ui/badge';
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
import { sellerStatusLabels, sellerStatusVariant } from '@/lib/sellerStatus';
import { priceChanges } from '@/routes/panel/moderation';
import { reinstate, suspend } from '@/routes/panel/offers';
import type {
    ModeratorOfferAction,
    OfferStatus,
    OfferSuspension,
    SellerStatus,
} from '@/types';

export type PanelOffer = {
    id: number;
    seller: string | null;
    seller_status: SellerStatus | null;
    price_cents: number;
    pending_price_cents: number | null;
    stock: number;
    status: OfferStatus;
    suspension: OfferSuspension | null;
    allowed_actions: ModeratorOfferAction[];
    version: number;
};

const props = defineProps<{ offer: PanelOffer }>();

// Ceną i stanem zarządza sprzedawca w swoim portalu. Moderator dostaje
// wyłącznie akcje z `allowed_actions` (agregat), UI nie zgaduje przejść.
function can(action: ModeratorOfferAction): boolean {
    return props.offer.allowed_actions.includes(action);
}

const visitOptions = { preserveScroll: true } as const;

const suspendOpen = ref(false);
const suspendForm = useForm({ note: '' });

function openSuspend() {
    suspendForm.reset();
    suspendForm.clearErrors();
    suspendOpen.value = true;
}

function submitSuspend() {
    suspendForm.post(suspend(props.offer.id).url, {
        ...visitOptions,
        onSuccess: () => {
            suspendOpen.value = false;
            suspendForm.reset();
        },
    });
}

const reinstateOpen = ref(false);
const reinstating = ref(false);

function confirmReinstate() {
    router.post(
        reinstate(props.offer.id).url,
        {},
        {
            ...visitOptions,
            onStart: () => (reinstating.value = true),
            onFinish: () => {
                reinstating.value = false;
                reinstateOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <li class="flex flex-col gap-2 rounded-md border p-3">
        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
            <span class="font-medium">
                Oferta #{{ offer.id }}
                <span class="font-normal text-muted-foreground">
                    {{ offer.seller ?? 'brak sprzedawcy' }}, wersja
                    {{ offer.version }}
                </span>
            </span>
            <span class="flex flex-wrap items-center gap-2">
                <Badge
                    v-if="
                        offer.seller_status && offer.seller_status !== 'active'
                    "
                    :variant="sellerStatusVariant(offer.seller_status)"
                >
                    sprzedawca: {{ sellerStatusLabels[offer.seller_status] }}
                </Badge>
                <OfferStatusBadge
                    :status="offer.status"
                    :is-sold-out="
                        offer.status === 'published' && offer.stock === 0
                    "
                    :has-pending-price="offer.pending_price_cents !== null"
                />
            </span>
        </div>

        <dl class="flex flex-wrap gap-6 text-sm">
            <div>
                <dt class="text-xs text-muted-foreground">Cena</dt>
                <dd>{{ formatPriceCents(offer.price_cents) }}</dd>
            </div>
            <div v-if="offer.pending_price_cents !== null">
                <dt class="text-xs text-muted-foreground">Zgłoszona cena</dt>
                <dd>{{ formatPriceCents(offer.pending_price_cents) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Stan</dt>
                <dd>{{ offer.stock }}</dd>
            </div>
        </dl>

        <p
            v-if="offer.suspension"
            class="text-sm text-muted-foreground"
            role="note"
        >
            <template v-if="offer.suspension.kind === 'seller_blocked'">
                Zawieszona z powodu blokady sprzedawcy — wróci po odblokowaniu.
            </template>
            <template v-else>
                Zawieszona przez moderatora: {{ offer.suspension.note }}
            </template>
        </p>

        <div
            v-if="offer.allowed_actions.length > 0"
            class="flex flex-wrap gap-2"
        >
            <Button
                v-if="can('suspend')"
                size="sm"
                variant="destructive"
                @click="openSuspend"
            >
                Zawieś
            </Button>
            <Button
                v-if="can('reinstate')"
                size="sm"
                variant="outline"
                @click="reinstateOpen = true"
            >
                Przywróć
            </Button>
            <Button
                v-if="can('approve_price_change') || can('reject_price_change')"
                size="sm"
                variant="outline"
                as-child
            >
                <Link :href="priceChanges()">Rozpatrz zmianę ceny</Link>
            </Button>
        </div>

        <Dialog v-model:open="suspendOpen">
            <DialogContent>
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitSuspend"
                >
                    <DialogHeader>
                        <DialogTitle
                            >Zawiesić ofertę #{{ offer.id }}?</DialogTitle
                        >
                        <DialogDescription>
                            Oferta zniknie z wyszukiwarki. Sprzedawca zobaczy
                            powód przy ofercie.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label :for="`suspend-note-${offer.id}`">Powód</Label>
                        <!-- Brak komponentu Textarea w components/ui: natywny element z klasami `Input`. -->
                        <textarea
                            :id="`suspend-note-${offer.id}`"
                            v-model="suspendForm.note"
                            required
                            maxlength="500"
                            rows="4"
                            :aria-invalid="!!suspendForm.errors.note"
                            class="min-h-20 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none selection:bg-primary selection:text-primary-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                        />
                        <InputError :message="suspendForm.errors.note" />
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
                            :disabled="suspendForm.processing"
                        >
                            Zawieś
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="reinstateOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >Przywrócić ofertę #{{ offer.id }}?</DialogTitle
                    >
                    <DialogDescription>
                        Oferta wróci do sprzedaży i do wyszukiwarki.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            Anuluj
                        </Button>
                    </DialogClose>
                    <Button :disabled="reinstating" @click="confirmReinstate">
                        Przywróć
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </li>
</template>
