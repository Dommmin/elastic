<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apply } from '@/routes/seller';
import { store } from '@/routes/seller/apply';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Zostań sprzedawcą', href: apply() }],
    },
});

const form = useForm({
    name: '',
    city: '',
});

function submit() {
    form.post(store().url);
}
</script>

<template>
    <Head title="Zostań sprzedawcą" />

    <div class="flex max-w-xl flex-col gap-4 p-4">
        <Card>
            <form class="flex flex-col gap-6" @submit.prevent="submit">
                <CardHeader>
                    <CardTitle>Zostań sprzedawcą</CardTitle>
                    <CardDescription>
                        Podaj nazwę sklepu i miasto. Moderator sprawdzi
                        zgłoszenie, a po akceptacji dodasz pierwsze oferty.
                    </CardDescription>
                </CardHeader>

                <CardContent class="flex flex-col gap-4">
                    <div class="grid gap-2">
                        <Label for="name">Nazwa sklepu</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            required
                            minlength="3"
                            maxlength="120"
                            autocomplete="organization"
                            :aria-invalid="!!form.errors.name"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="city">Miasto</Label>
                        <Input
                            id="city"
                            v-model="form.city"
                            required
                            maxlength="120"
                            autocomplete="address-level2"
                            :aria-invalid="!!form.errors.city"
                        />
                        <InputError :message="form.errors.city" />
                    </div>

                    <InputError
                        :message="
                            (form.errors as Record<string, string>).domain
                        "
                    />
                </CardContent>

                <CardFooter>
                    <Button type="submit" :disabled="form.processing">
                        Wyślij zgłoszenie
                    </Button>
                </CardFooter>
            </form>
        </Card>
    </div>
</template>
