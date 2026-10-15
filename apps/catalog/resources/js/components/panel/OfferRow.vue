<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { formatPriceCents } from '@/lib/currency';

export type PanelOffer = {
    id: number;
    seller: string | null;
    price_cents: number;
    stock: number;
    status: 'draft' | 'published' | 'suspended' | 'withdrawn';
    seller_status: 'pending' | 'active' | 'rejected' | 'blocked' | null;
    version: number;
};

defineProps<{ offer: PanelOffer }>();

// Admin tylko ogląda: ceną i stanem zarządza sprzedawca w swoim portalu,
// a moderator zawiesza lub przywraca oferty osobnymi akcjami.
const statusLabels: Record<PanelOffer['status'], string> = {
    draft: 'szkic',
    published: 'opublikowana',
    suspended: 'zawieszona',
    withdrawn: 'wycofana',
};
</script>

<template>
    <li class="flex flex-col gap-2 rounded-md border p-3">
        <div class="flex items-center justify-between text-sm">
            <span class="font-medium">
                Oferta #{{ offer.id }}
                <span class="font-normal text-muted-foreground">
                    {{ offer.seller ?? 'brak sprzedawcy' }}, wersja
                    {{ offer.version }}
                </span>
            </span>
            <span class="flex items-center gap-2">
                <Badge
                    v-if="offer.seller_status === 'blocked'"
                    variant="destructive"
                >
                    sprzedawca zablokowany
                </Badge>
                <Badge
                    :variant="
                        offer.status === 'published' ? 'default' : 'secondary'
                    "
                >
                    {{ statusLabels[offer.status] }}
                </Badge>
            </span>
        </div>

        <dl class="flex gap-6 text-sm">
            <div>
                <dt class="text-xs text-muted-foreground">Cena</dt>
                <dd>{{ formatPriceCents(offer.price_cents) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Stan</dt>
                <dd>{{ offer.stock }}</dd>
            </div>
        </dl>
    </li>
</template>
