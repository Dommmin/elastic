<script setup lang="ts">
import { Star } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { formatPriceCents } from '@/lib/currency';
import type { ProductHit } from '@/types/search';

defineProps<{
    product: ProductHit;
}>();
</script>

<template>
    <Card class="h-full">
        <CardContent class="flex h-full flex-col gap-2 p-4">
            <div class="flex items-start justify-between gap-2">
                <p class="text-sm font-medium text-muted-foreground">
                    {{ product.brand ?? '—' }}
                </p>
                <Badge
                    v-if="!product.in_stock"
                    variant="outline"
                    class="shrink-0 text-muted-foreground"
                >
                    Niedostępny
                </Badge>
            </div>

            <h3 class="line-clamp-2 leading-snug font-semibold">
                {{ product.name }}
            </h3>

            <div
                v-if="product.rating_count > 0"
                class="flex items-center gap-1 text-sm text-muted-foreground"
            >
                <Star class="size-3.5 fill-current text-amber-500" />
                <span>{{ product.rating_avg?.toFixed(1) }}</span>
                <span>({{ product.rating_count }})</span>
            </div>

            <div class="mt-auto flex flex-col gap-1 pt-2">
                <!-- Najniższa cena — z inner_hits (App\Services\ProductSearchService::cheapestOfferClause) -->
                <p v-if="product.cheapest_offer" class="text-lg font-bold">
                    {{ formatPriceCents(product.cheapest_offer.price) }}
                </p>
                <p
                    v-if="
                        product.price_min !== null &&
                        product.price_max !== null &&
                        product.price_min !== product.price_max
                    "
                    class="text-xs text-muted-foreground"
                >
                    od {{ formatPriceCents(product.price_min) }} do
                    {{ formatPriceCents(product.price_max) }}
                </p>
                <p
                    v-if="product.cheapest_offer"
                    class="text-xs text-muted-foreground"
                >
                    {{ product.cheapest_offer.seller }} ·
                    {{ product.cheapest_offer.stock }} szt.
                </p>
            </div>
        </CardContent>
    </Card>
</template>
