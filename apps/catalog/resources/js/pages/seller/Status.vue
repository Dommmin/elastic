<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BlockedBanner from '@/components/seller/BlockedBanner.vue';
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
import { sellerStatusLabels, sellerStatusVariant } from '@/lib/sellerStatus';
import {
    dashboard as sellerDashboard,
    status as sellerStatus,
} from '@/routes/seller';
import type { SellerProfile } from '@/types';

defineProps<{ seller: SellerProfile }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Portal sprzedawcy', href: sellerStatus() }],
    },
});

const page = usePage();
// Np. „To konto ma już profil sprzedawcy” po ponownym wysłaniu formularza.
const domainError = computed(() => page.props.errors?.domain);
</script>

<template>
    <Head title="Status sprzedawcy" />

    <div class="flex max-w-xl flex-col gap-4 p-4">
        <BlockedBanner
            v-if="seller.status === 'blocked'"
            :reason="seller.status_reason"
        />

        <Alert v-if="domainError" role="status">
            <AlertDescription>{{ domainError }}</AlertDescription>
        </Alert>

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center justify-between gap-2">
                    {{ seller.name }}
                    <Badge :variant="sellerStatusVariant(seller.status)">
                        {{ sellerStatusLabels[seller.status] }}
                    </Badge>
                </CardTitle>
                <CardDescription>{{ seller.city }}</CardDescription>
            </CardHeader>

            <CardContent class="flex flex-col gap-2 text-sm">
                <template v-if="seller.status === 'pending'">
                    <p class="font-medium">
                        Zgłoszenie oczekuje na akceptację moderatora.
                    </p>
                    <p class="text-muted-foreground">
                        Po akceptacji odblokujemy portal sprzedawcy i dodawanie
                        ofert.
                    </p>
                </template>

                <template v-else-if="seller.status === 'rejected'">
                    <p class="font-medium">Zgłoszenie zostało odrzucone.</p>
                    <p
                        v-if="seller.status_reason"
                        class="text-muted-foreground"
                    >
                        Powód: {{ seller.status_reason }}
                    </p>
                    <!-- Rejected jest końcowy (SellerStatus): konto nie złoży drugiego zgłoszenia. -->
                    <p class="text-muted-foreground">
                        Decyzja jest ostateczna — z tego konta nie można złożyć
                        nowego zgłoszenia.
                    </p>
                </template>

                <template v-else-if="seller.status === 'active'">
                    <p class="font-medium">Konto sprzedawcy jest aktywne.</p>
                    <p class="text-muted-foreground">
                        Możesz wystawiać oferty w portalu sprzedawcy.
                    </p>
                </template>

                <p v-else class="text-muted-foreground">
                    Skontaktuj się z moderatorem, aby wyjaśnić blokadę.
                </p>
            </CardContent>
            <CardFooter
                v-if="seller.status === 'active' || seller.status === 'blocked'"
            >
                <Button as-child variant="outline">
                    <Link :href="sellerDashboard()">Przejdź do portalu</Link>
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
