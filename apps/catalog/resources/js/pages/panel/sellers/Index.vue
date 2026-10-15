<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { sellerStatusLabels, sellerStatusVariant } from '@/lib/sellerStatus';
import { approve, block, index, reject, unblock } from '@/routes/panel/sellers';
import type { DirectorySeller, PendingSeller, SellerStatus } from '@/types';

type PaginatorLink = { url: string | null; label: string; active: boolean };

defineProps<{
    pending: PendingSeller[];
    sellers: {
        data: DirectorySeller[];
        links: PaginatorLink[];
        total: number;
    };
    filters: { status: SellerStatus | null };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Panel: sprzedawcy', href: index() }],
    },
});

const page = usePage();
// Odmowa domeny (np. akceptacja odrzuconego) wraca jako błąd `domain`.
const domainError = computed(() => page.props.errors?.domain);

const statusFilters: { value: SellerStatus | null; label: string }[] = [
    { value: null, label: 'Wszyscy' },
    { value: 'pending', label: 'Oczekujący' },
    { value: 'active', label: 'Aktywni' },
    { value: 'rejected', label: 'Odrzuceni' },
    { value: 'blocked', label: 'Zablokowani' },
];

function filterUrl(status: SellerStatus | null): string {
    return index(status ? { query: { status } } : undefined).url;
}

function pageLabel(label: string): string {
    if (label.includes('Previous')) {
        return '‹ Poprzednia';
    }

    if (label.includes('Next')) {
        return 'Następna ›';
    }

    return label;
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString('pl-PL') : '—';
}

const visitOptions = { preserveScroll: true } as const;

function approveSeller(id: number) {
    router.post(approve(id).url, {}, visitOptions);
}

function unblockSeller(id: number) {
    router.post(unblock(id).url, {}, visitOptions);
}

// Jeden dialog na powód obsługuje i odrzucenie, i blokadę — różnią się tylko tekstami i trasą.
type ReasonAction = 'reject' | 'block';
const dialogOpen = ref(false);
const reasonTarget = ref<{
    action: ReasonAction;
    id: number;
    name: string;
} | null>(null);
const reasonForm = useForm({ reason: '' });

const reasonCopy: Record<
    ReasonAction,
    { title: string; description: string; submit: string }
> = {
    reject: {
        title: 'Odrzucić zgłoszenie?',
        description: 'Sprzedawca zobaczy powód na ekranie statusu.',
        submit: 'Odrzuć',
    },
    block: {
        title: 'Zablokować sprzedawcę?',
        description:
            'Wszystkie jego oferty zostaną zawieszone. Sprzedawca zobaczy powód w portalu.',
        submit: 'Zablokuj',
    },
};

function openReasonDialog(action: ReasonAction, id: number, name: string) {
    reasonTarget.value = { action, id, name };
    reasonForm.reset();
    reasonForm.clearErrors();
    dialogOpen.value = true;
}

function submitReason() {
    const target = reasonTarget.value;

    if (!target) {
        return;
    }

    const route = target.action === 'reject' ? reject : block;

    reasonForm.post(route(target.id).url, {
        ...visitOptions,
        onSuccess: () => {
            dialogOpen.value = false;
            reasonForm.reset();
        },
    });
}
</script>

<template>
    <Head title="Panel: sprzedawcy" />

    <div class="flex flex-col gap-6 p-4">
        <Alert v-if="domainError" variant="destructive" role="alert">
            <AlertDescription>{{ domainError }}</AlertDescription>
        </Alert>

        <section class="flex flex-col gap-2">
            <h2 class="text-lg font-semibold">
                Oczekujący na akceptację
                <span class="text-sm font-normal text-muted-foreground">
                    ({{ pending.length }})
                </span>
            </h2>

            <p
                v-if="pending.length === 0"
                class="text-sm text-muted-foreground"
            >
                Brak zgłoszeń do rozpatrzenia.
            </p>

            <div v-else class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2 font-medium">Nazwa</th>
                            <th class="px-3 py-2 font-medium">Miasto</th>
                            <th class="px-3 py-2 font-medium">E-mail</th>
                            <th class="px-3 py-2 font-medium">Zgłoszono</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Decyzja
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="seller in pending"
                            :key="seller.id"
                            class="border-b last:border-0"
                        >
                            <td class="px-3 py-2 font-medium">
                                {{ seller.name }}
                            </td>
                            <td class="px-3 py-2">{{ seller.city }}</td>
                            <td class="px-3 py-2">
                                {{ seller.email ?? 'bez konta' }}
                            </td>
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ formatDate(seller.created_at) }}
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-2">
                                    <Button
                                        size="sm"
                                        @click="approveSeller(seller.id)"
                                    >
                                        Akceptuj
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="
                                            openReasonDialog(
                                                'reject',
                                                seller.id,
                                                seller.name,
                                            )
                                        "
                                    >
                                        Odrzuć
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">Wszyscy sprzedawcy</h2>
                <span class="text-sm text-muted-foreground">
                    {{ sellers.total }} sprzedawców
                </span>
            </div>

            <nav class="flex flex-wrap gap-1" aria-label="Filtr statusu">
                <Link
                    v-for="filter in statusFilters"
                    :key="filter.label"
                    :href="filterUrl(filter.value)"
                    preserve-scroll
                    class="rounded border px-3 py-1 text-sm"
                    :class="
                        filters.status === filter.value
                            ? 'bg-primary text-primary-foreground'
                            : ''
                    "
                >
                    {{ filter.label }}
                </Link>
            </nav>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2 font-medium">ID</th>
                            <th class="px-3 py-2 font-medium">Nazwa</th>
                            <th class="px-3 py-2 font-medium">Miasto</th>
                            <th class="px-3 py-2 font-medium">Konto</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Akcje
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="seller in sellers.data"
                            :key="seller.id"
                            class="border-b last:border-0"
                        >
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ seller.id }}
                            </td>
                            <td class="px-3 py-2 font-medium">
                                {{ seller.name }}
                            </td>
                            <td class="px-3 py-2">{{ seller.city }}</td>
                            <td class="px-3 py-2">
                                <span v-if="seller.has_account">
                                    {{ seller.email }}
                                </span>
                                <Badge v-else variant="outline">
                                    bez konta
                                </Badge>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-col gap-1">
                                    <Badge
                                        :variant="
                                            sellerStatusVariant(seller.status)
                                        "
                                    >
                                        {{ sellerStatusLabels[seller.status] }}
                                    </Badge>
                                    <span
                                        v-if="seller.status_reason"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ seller.status_reason }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-2">
                                    <Button
                                        v-if="seller.status === 'active'"
                                        size="sm"
                                        variant="destructive"
                                        @click="
                                            openReasonDialog(
                                                'block',
                                                seller.id,
                                                seller.name,
                                            )
                                        "
                                    >
                                        Zablokuj
                                    </Button>
                                    <Button
                                        v-else-if="seller.status === 'blocked'"
                                        size="sm"
                                        variant="outline"
                                        @click="unblockSeller(seller.id)"
                                    >
                                        Odblokuj
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="sellers.data.length === 0">
                            <td
                                colspan="6"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                Brak sprzedawców.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav class="flex flex-wrap gap-1" aria-label="Strony">
                <template v-for="(link, i) in sellers.links" :key="i">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="rounded border px-3 py-1 text-sm"
                        :class="
                            link.active
                                ? 'bg-primary text-primary-foreground'
                                : ''
                        "
                    >
                        {{ pageLabel(link.label) }}
                    </Link>
                    <span
                        v-else
                        class="rounded border px-3 py-1 text-sm text-muted-foreground"
                    >
                        {{ pageLabel(link.label) }}
                    </span>
                </template>
            </nav>
        </section>

        <Dialog v-model:open="dialogOpen">
            <DialogContent v-if="reasonTarget">
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitReason"
                >
                    <DialogHeader>
                        <DialogTitle>
                            {{ reasonCopy[reasonTarget.action].title }}
                        </DialogTitle>
                        <DialogDescription>
                            {{ reasonTarget.name }} —
                            {{ reasonCopy[reasonTarget.action].description }}
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="reason">Powód</Label>
                        <!-- Brak komponentu Textarea w components/ui: natywny element z klasami `Input`. -->
                        <textarea
                            id="reason"
                            v-model="reasonForm.reason"
                            required
                            maxlength="500"
                            rows="4"
                            :aria-invalid="!!reasonForm.errors.reason"
                            class="min-h-20 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none selection:bg-primary selection:text-primary-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                        />
                        <InputError :message="reasonForm.errors.reason" />
                        <InputError :message="domainError" />
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary">
                                Anuluj
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="reasonForm.processing"
                        >
                            {{ reasonCopy[reasonTarget.action].submit }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
