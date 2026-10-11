<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { zlotyFromCents } from '@/lib/currency';
import { deactivate, update } from '@/routes/panel/offers';

export type PanelOffer = {
    id: number;
    seller: string | null;
    price_cents: number;
    stock: number;
    active: boolean;
    version: number;
};

const props = defineProps<{ offer: PanelOffer }>();

// Własny formularz na wiersz: zapis jednej oferty nie rusza błędów ani
// stanu „processing” pozostałych. Cena w zł; przecinek obsługuje serwer.
const form = useForm({
    price: String(zlotyFromCents(props.offer.price_cents)),
    stock: String(props.offer.stock),
});
const deactivateForm = useForm({});

function save() {
    form.put(update(props.offer.id).url, { preserveScroll: true });
}

function confirmDeactivate() {
    if (!confirm(`Dezaktywować ofertę #${props.offer.id}?`)) {
        return;
    }

    deactivateForm.post(deactivate(props.offer.id).url, {
        preserveScroll: true,
    });
}
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
            <span v-if="!offer.active" class="text-muted-foreground">
                nieaktywna
            </span>
        </div>

        <form class="flex flex-wrap items-start gap-2" @submit.prevent="save">
            <div class="grid gap-1">
                <label class="text-xs" :for="`price-${offer.id}`">
                    Cena (zł)
                </label>
                <Input
                    :id="`price-${offer.id}`"
                    v-model="form.price"
                    inputmode="decimal"
                    class="w-32"
                    required
                />
                <InputError :message="form.errors.price" />
            </div>
            <div class="grid gap-1">
                <label class="text-xs" :for="`stock-${offer.id}`">Stan</label>
                <Input
                    :id="`stock-${offer.id}`"
                    v-model="form.stock"
                    type="number"
                    min="0"
                    class="w-24"
                    required
                />
                <InputError :message="form.errors.stock" />
            </div>
            <div class="flex gap-2 self-end">
                <Button type="submit" size="sm" :disabled="form.processing">
                    Zapisz
                </Button>
                <Button
                    v-if="offer.active"
                    type="button"
                    size="sm"
                    variant="destructive"
                    :disabled="deactivateForm.processing"
                    @click="confirmDeactivate"
                >
                    Dezaktywuj
                </Button>
            </div>
        </form>
    </li>
</template>
