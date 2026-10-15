<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { offerStatusLabels } from '@/lib/offer';
import type { OfferStatus } from '@/types';

const props = defineProps<{
    status: OfferStatus;
    isSoldOut?: boolean;
    hasPendingPrice?: boolean;
}>();

const variant = computed(() => {
    switch (props.status) {
        case 'published':
            return props.isSoldOut ? 'outline' : 'default';
        case 'suspended':
            return 'destructive';
        default:
            return 'secondary';
    }
});
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-1">
        <Badge :variant="variant">{{ offerStatusLabels[status] }}</Badge>
        <!-- „Wyprzedana” to nie status agregatu, tylko opublikowana z zerowym stanem. -->
        <Badge v-if="isSoldOut" variant="outline">wyprzedana</Badge>
        <Badge v-if="hasPendingPrice" variant="outline">
            cena u moderatora
        </Badge>
    </span>
</template>
