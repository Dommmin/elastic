<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatPriceCents } from '@/lib/currency';
import { decide } from '@/routes/fake-gateway';

// Strona udaje serwis zewnętrznego dostawcy płatności, nie część marketplace'u
// (layout `null` w app.ts). Wynik trafia do sklepu webhookiem, nie stąd.
const props = defineProps<{
    payment: {
        id: string;
        amount_cents: number;
        currency: string;
        status: 'pending' | 'succeeded' | 'failed';
        expires_at: string;
        expired: boolean;
    };
    developerTools: boolean;
}>();

const form = useForm({ action: '' });
const page = usePage();
const domainError = computed(() => page.props.errors?.domain);

const deadline = computed(() =>
    new Intl.DateTimeFormat('pl-PL', {
        dateStyle: 'medium',
        timeStyle: 'medium',
    }).format(new Date(props.payment.expires_at)),
);

const decided = computed(() => props.payment.status !== 'pending');

function send(action: string) {
    form.action = action;
    form.post(decide(props.payment.id).url);
}
</script>

<template>
    <Head title="Bramka płatności" />

    <div class="flex min-h-screen items-center justify-center bg-muted/40 p-4">
        <div class="flex w-full max-w-md flex-col gap-4">
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center justify-between gap-2">
                        Bramka płatności
                        <Badge variant="outline">symulacja</Badge>
                    </CardTitle>
                    <CardDescription>
                        Płatność dla odbiorcy: Marketplace
                    </CardDescription>
                </CardHeader>

                <CardContent class="flex flex-col gap-3 text-sm">
                    <Alert v-if="domainError" role="alert">
                        <AlertDescription>{{ domainError }}</AlertDescription>
                    </Alert>

                    <div class="flex items-baseline justify-between">
                        <span class="text-muted-foreground">Do zapłaty</span>
                        <span class="text-2xl font-semibold" data-test="amount">
                            {{ formatPriceCents(payment.amount_cents) }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Odbiorca</span>
                        <span>Marketplace</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Termin</span>
                        <span>{{ deadline }}</span>
                    </div>

                    <p v-if="decided" class="font-medium" role="status">
                        {{
                            payment.status === 'succeeded'
                                ? 'Płatność zaksięgowana.'
                                : 'Płatność odrzucona.'
                        }}
                    </p>
                    <p
                        v-else-if="payment.expired"
                        class="font-medium text-destructive"
                        role="status"
                    >
                        Sesja wygasła — wróć do sklepu i rozpocznij płatność
                        ponownie.
                    </p>
                </CardContent>

                <CardFooter
                    v-if="!decided && !payment.expired"
                    class="flex gap-2"
                >
                    <Button :disabled="form.processing" @click="send('pay')">
                        Zapłać
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="form.processing"
                        @click="send('decline')"
                    >
                        Odmów
                    </Button>
                </CardFooter>
            </Card>

            <Card v-if="developerTools" class="border-dashed">
                <CardHeader>
                    <CardTitle class="text-sm">
                        Tylko środowisko deweloperskie
                    </CardTitle>
                    <CardDescription>
                        Narzędzia do odtwarzania wyścigów. Nie istnieją poza
                        środowiskiem local i testami.
                    </CardDescription>
                </CardHeader>
                <CardFooter class="flex flex-wrap gap-2">
                    <Button
                        variant="secondary"
                        size="sm"
                        :disabled="form.processing || !decided"
                        @click="send('resend')"
                    >
                        Wyślij webhook ponownie
                    </Button>
                    <Button
                        variant="secondary"
                        size="sm"
                        :disabled="form.processing || decided"
                        @click="send('pay_expired')"
                    >
                        Zapłać mimo wygaśnięcia
                    </Button>
                </CardFooter>
            </Card>
        </div>
    </div>
</template>
