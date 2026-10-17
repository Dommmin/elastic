<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { OrderStatus } from '@/types';

// Tekst przychodzi z serwera (Ordering\Infrastructure\ReadModel\OrderStatusLabel);
// tu tylko dobieramy wygląd po statusie.
const props = defineProps<{ status: OrderStatus; label: string }>();

const variant = computed(() => {
    switch (props.status) {
        case 'paid':
        case 'shipped':
            return 'default';
        case 'rejected':
        case 'expired':
        case 'cancelled':
            return 'destructive';
        default:
            return 'outline';
    }
});
</script>

<template>
    <Badge :variant="variant">{{ label }}</Badge>
</template>
