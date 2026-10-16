<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Odliczanie startuje od `seconds` z serwera i liczy lokalnie przyrostami,
// więc rozjazd zegara przeglądarki nie wpływa na wynik. Nowa wartość z
// odświeżenia propsów resetuje licznik.
const props = defineProps<{ seconds: number }>();

const remaining = ref(props.seconds);
let timer: ReturnType<typeof setInterval> | undefined;

watch(
    () => props.seconds,
    (value) => (remaining.value = value),
);

onMounted(() => {
    timer = setInterval(() => {
        remaining.value = Math.max(0, remaining.value - 1);
    }, 1000);
});
onBeforeUnmount(() => clearInterval(timer));

const text = computed(() => {
    const minutes = Math.floor(remaining.value / 60);
    const seconds = remaining.value % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')}`;
});
</script>

<template>
    <p class="text-sm" role="timer">
        <template v-if="remaining > 0">
            Zostało
            <strong class="tabular-nums">{{ text }}</strong>
            na zapłatę
        </template>
        <template v-else>Czas na płatność minął.</template>
    </p>
</template>
