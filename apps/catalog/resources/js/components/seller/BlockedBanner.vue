<script setup lang="ts">
import { ShieldAlert } from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

defineProps<{ reason: string | null }>();
</script>

<template>
    <!-- Zablokowany sprzedawca widzi portal tylko do odczytu, więc baner stoi nad całą treścią. -->
    <Alert variant="destructive" role="alert">
        <ShieldAlert />
        <AlertTitle>Konto sprzedawcy zablokowane</AlertTitle>
        <AlertDescription>
            <p>
                Twoje oferty zostały zawieszone i nie możesz wprowadzać zmian.
            </p>
            <p v-if="reason">Powód: {{ reason }}</p>
        </AlertDescription>
    </Alert>
</template>
