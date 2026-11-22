<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatPriceCents } from '@/lib/currency';
import { relativeTime } from '@/lib/relativeTime';
import { index } from '@/routes/panel/sellers360';
import type { Seller360Card, Seller360SectionKey } from '@/types';

// `seller` jest null, gdy ES leży (es_available: false): strona pokazuje komunikat zamiast 500.
defineProps<{
    seller: Seller360Card | null;
    sellerId: number;
    es_available: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Panel: Seller 360', href: index() }],
    },
});

const titles: Record<Seller360SectionKey, string> = {
    profile: 'Profil',
    offers: 'Oferty',
    sales: 'Sprzedaż (30 dni)',
    billing: 'Rozliczenia',
    complaints: 'Reklamacje',
    registry: 'Rejestr i kurier',
};

// Pola z kwotą w groszach i z ułamkiem (konwersja, terminowość) formatujemy po nazwie.
function formatValue(field: string, value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    if (field.endsWith('_cents') && typeof value === 'number') {
        return formatPriceCents(value);
    }

    if (
        (field.startsWith('conversion') || field === 'on_time_rate') &&
        typeof value === 'number'
    ) {
        return `${(value * 100).toFixed(1)}%`;
    }

    if (Array.isArray(value)) {
        return value.length ? value.join(', ') : '—';
    }

    if (typeof value === 'object') {
        return Object.entries(value as Record<string, unknown>)
            .map(([k, v]) => `${k}: ${formatValue(k, v)}`)
            .join(', ');
    }

    return String(value);
}

function scalarEntries(data: Record<string, unknown>): [string, unknown][] {
    return Object.entries(data).filter(([key]) => key !== 'recent_texts');
}
</script>

<template>
    <Head :title="`Seller 360: ${seller?.name ?? sellerId}`" />

    <div v-if="!seller" class="flex flex-col gap-6 p-4">
        <Alert variant="destructive" role="alert">
            <AlertDescription>
                Elasticsearch jest niedostępny, karta sprzedawcy {{ sellerId }}
                chwilowo niewidoczna.
            </AlertDescription>
        </Alert>
        <Link
            :href="index()"
            class="text-sm underline-offset-4 hover:underline"
        >
            ‹ Wróć do wyszukiwarki
        </Link>
    </div>

    <div v-else class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h1 class="text-xl font-semibold">
                    {{ seller.name ?? 'brak danych z catalog' }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    ID {{ seller.id }} · NIP {{ seller.nip ?? '—' }} ·
                    {{ seller.region ?? 'region nieznany' }}
                    <Badge
                        v-if="seller.synthetic"
                        variant="outline"
                        class="ml-1"
                        >syntetyczny</Badge
                    >
                </p>
            </div>
            <Link
                :href="index()"
                class="text-sm underline-offset-4 hover:underline"
            >
                ‹ Wróć do wyszukiwarki
            </Link>
        </div>

        <TooltipProvider :delay-duration="0">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card v-for="section in seller.sections" :key="section.key">
                    <CardHeader>
                        <CardTitle
                            class="flex items-center justify-between gap-2"
                        >
                            {{ titles[section.key] }}
                            <Tooltip
                                v-if="
                                    section.meta && section.meta.status !== 'ok'
                                "
                            >
                                <TooltipTrigger>
                                    <Badge variant="destructive">
                                        {{
                                            section.meta.status === 'stale'
                                                ? 'nieaktualne'
                                                : 'błąd'
                                        }}
                                    </Badge>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>
                                        {{
                                            section.meta.error ??
                                            'brak opisu błędu'
                                        }}
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                        </CardTitle>
                        <CardDescription>
                            <template v-if="section.meta">
                                zapisano
                                {{ relativeTime(section.meta.updated_at) }} ·
                                źródło
                                {{ relativeTime(section.meta.source_ts) }}
                            </template>
                            <template v-else
                                >źródło: {{ section.source }}</template
                            >
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="!section.present"
                            class="text-sm text-muted-foreground"
                        >
                            {{ section.missing_label }}
                        </p>
                        <template v-else>
                            <dl
                                class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm"
                            >
                                <template
                                    v-for="[field, value] in scalarEntries(
                                        section.data,
                                    )"
                                    :key="field"
                                >
                                    <dt class="text-muted-foreground">
                                        {{ field }}
                                    </dt>
                                    <dd class="break-words">
                                        {{ formatValue(field, value) }}
                                    </dd>
                                </template>
                            </dl>
                            <ul
                                v-if="Array.isArray(section.data.recent_texts)"
                                class="mt-3 flex max-h-48 flex-col gap-1 overflow-y-auto text-sm"
                            >
                                <li
                                    v-for="(text, i) in section.data
                                        .recent_texts as string[]"
                                    :key="i"
                                    class="rounded bg-muted/50 px-2 py-1"
                                >
                                    {{ text }}
                                </li>
                            </ul>
                        </template>
                    </CardContent>
                </Card>
            </div>
        </TooltipProvider>
    </div>
</template>
