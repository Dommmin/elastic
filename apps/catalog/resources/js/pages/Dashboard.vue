<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { sellerStatusLabels, sellerStatusVariant } from '@/lib/sellerStatus';
import { dashboard } from '@/routes';
import { apply as sellerApply, status as sellerStatus } from '@/routes/seller';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const page = usePage();
// Rejestracja Fortify kończy się tutaj, więc to naturalne miejsce na wejście do portalu sprzedawcy.
const seller = computed(() => page.props.auth.seller);
</script>

<template>
    <Head title="Dashboard" />

    <div
        class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
        <Card v-if="seller">
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    Portal sprzedawcy
                    <Badge :variant="sellerStatusVariant(seller.status)">
                        {{ sellerStatusLabels[seller.status] }}
                    </Badge>
                </CardTitle>
                <CardDescription>{{ seller.name }}</CardDescription>
            </CardHeader>
            <CardFooter>
                <Button as-child variant="outline">
                    <Link :href="sellerStatus()">Przejdź do portalu</Link>
                </Button>
            </CardFooter>
        </Card>

        <Card v-else>
            <CardHeader>
                <CardTitle>Zostań sprzedawcą</CardTitle>
                <CardDescription>
                    Wystawiaj własne oferty w katalogu. Zgłoszenie zajmie
                    chwilę, a moderator zaakceptuje je w panelu.
                </CardDescription>
            </CardHeader>
            <CardFooter>
                <Button as-child>
                    <Link :href="sellerApply()">Złóż zgłoszenie</Link>
                </Button>
            </CardFooter>
        </Card>

        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div
                class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <PlaceholderPattern />
            </div>
            <div
                class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <PlaceholderPattern />
            </div>
            <div
                class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <PlaceholderPattern />
            </div>
        </div>
        <div
            class="relative min-h-[100vh] flex-1 rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border"
        >
            <PlaceholderPattern />
        </div>
    </div>
</template>
