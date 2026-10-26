<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { onMounted } from 'vue';

// Strona `make errors-smoke` (ETAP 11b): rzuca błąd po zamontowaniu, żeby
// sprawdzić drogę przeglądarka → tunel /_errors → GlitchTip. Trasa istnieje
// tylko w APP_ENV=local (routes/debug.php).
const props = defineProps<{ marker: string }>();

onMounted(() => {
    throw new Error(`errors-smoke:frontend:${props.marker}`);
});
</script>

<template>
    <Head title="errors-smoke" />
    <main class="p-8">
        <h1 class="text-xl font-semibold">errors-smoke</h1>
        <p>Ta strona celowo rzuca błąd: {{ marker }}</p>
    </main>
</template>
