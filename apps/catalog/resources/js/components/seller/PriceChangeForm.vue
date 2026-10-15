<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatPriceCents } from '@/lib/currency';
import { changesLeftLabel, parsePriceToCents } from '@/lib/offer';
import { price } from '@/routes/seller/offers';
import type { PriceGuard } from '@/types';

const props = defineProps<{
    offerId: number;
    /** Wersja oferty z wyrenderowanej strony — serwer odmówi, jeśli jest nieaktualna. */
    version: number;
    currentCents: number;
    guard: PriceGuard;
}>();

const form = useForm({ price: '' });

const proposedCents = computed(() => parsePriceToCents(form.price));

// Podpowiedź liczona tą samą formułą co `Money::differsByMoreThanPercent`
// (na int, punkt odniesienia = obecna cena). Ostatnie słowo i tak ma domena.
const goesToModeration = computed(() => {
    const proposed = proposedCents.value;

    return (
        proposed !== null &&
        Math.abs(proposed - props.currentCents) * 100 >
            props.currentCents * props.guard.threshold_percent
    );
});

const changePercent = computed(() => {
    const proposed = proposedCents.value;

    if (proposed === null) {
        return null;
    }

    return Math.round(
        (Math.abs(proposed - props.currentCents) / props.currentCents) * 100,
    );
});

const limitReached = computed(() => props.guard.changes_left_today === 0);

function submit() {
    form.transform((data) => ({ ...data, version: props.version })).put(
        price(props.offerId).url,
        {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        },
    );
}
</script>

<template>
    <form class="flex flex-col gap-2" @submit.prevent="submit">
        <Label for="price">Nowa cena (zł)</Label>
        <div class="flex gap-2">
            <Input
                id="price"
                v-model="form.price"
                inputmode="decimal"
                autocomplete="off"
                :placeholder="formatPriceCents(currentCents)"
                :disabled="limitReached"
                class="max-w-48"
            />
            <Button
                type="submit"
                :disabled="limitReached || form.processing || !form.price"
            >
                Zmień cenę
            </Button>
        </div>
        <InputError :message="form.errors.price" />

        <p
            v-if="changePercent !== null && proposedCents !== currentCents"
            class="text-sm"
            :class="
                goesToModeration
                    ? 'text-amber-600 dark:text-amber-400'
                    : 'text-muted-foreground'
            "
            role="status"
        >
            Zmiana o {{ changePercent }}%
            <template v-if="goesToModeration">
                — trafi do akceptacji moderatora (próg
                {{ guard.threshold_percent }}%)
            </template>
            <template v-else>— zostanie wprowadzona od razu</template>
        </p>

        <p
            class="text-xs"
            :class="limitReached ? 'text-destructive' : 'text-muted-foreground'"
        >
            <template v-if="limitReached">
                Wykorzystano dzienny limit zmian ceny — spróbuj później.
            </template>
            <template v-else>
                Na dziś {{ changesLeftLabel(guard.changes_left_today) }}
                ceny.
            </template>
        </p>
    </form>
</template>
