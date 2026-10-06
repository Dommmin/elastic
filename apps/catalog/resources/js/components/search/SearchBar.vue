<script setup lang="ts">
import { Search } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { onBeforeUnmount, ref } from 'vue';
import { Input } from '@/components/ui/input';
import type { Suggestion } from '@/types/search';

/**
 * Autocomplete idzie przez zwykły `fetch`, NIE Inertię (docs/02-APLIKACJE.md,
 * sekcja 8 — Inertia zawsze przeładowuje propsy strony i dopisuje wizytę do
 * historii przeglądarki; podpowiedzi przy każdym znaku nie mogą tego robić).
 *
 * Obrona przed wyścigiem żądań — DOKŁADNIE ten sam problem co kolejność
 * zdarzeń w RabbitMQ (docs/06-PLAN-WDROZENIA.md, część B, akapit o
 * debounce): użytkownik pisze "lap", "lapt", "lapto", trzy żądania są
 * w locie, odpowiedzi wracają w losowej kolejności. Dwie niezależne linie
 * obrony:
 *  1. `AbortController` — każdy nowy znak anuluje POPRZEDNIE żądanie fetch,
 *  2. numer sekwencyjny — nawet gdyby anulowanie zawiodło (np. odpowiedź
 *     zdążyła dotrzeć tuż przed abortem), odpowiedź na nieaktualne żądanie
 *     jest jawnie odrzucana po numerze, nie po kolejności przyjścia.
 */
const props = defineProps<{
    modelValue: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
    search: [value: string];
}>();

const inputValue = ref(props.modelValue);
const suggestions = ref<Suggestion[]>([]);
const showSuggestions = ref(false);

let abortController: AbortController | null = null;
let requestSequence = 0;

async function fetchSuggestions(query: string) {
    abortController?.abort();

    if (query.trim() === '') {
        suggestions.value = [];

        return;
    }

    const sequence = ++requestSequence;
    abortController = new AbortController();

    try {
        const response = await fetch(
            `/api/suggest?q=${encodeURIComponent(query)}`,
            {
                signal: abortController.signal,
                headers: { Accept: 'application/json' },
            },
        );
        const data = (await response.json()) as { suggestions: Suggestion[] };

        // Odpowiedź na nieaktualne żądanie — odrzuć, nawet jeśli dotarła.
        if (sequence !== requestSequence) {
            return;
        }

        suggestions.value = data.suggestions;
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }

        suggestions.value = [];
    }
}

const debouncedFetchSuggestions = useDebounceFn(fetchSuggestions, 300);

function onInput(value: string) {
    inputValue.value = value;
    emit('update:modelValue', value);
    showSuggestions.value = true;
    debouncedFetchSuggestions(value);
}

const debouncedSearch = useDebounceFn(
    (value: string) => emit('search', value),
    300,
);

function onType(value: string) {
    onInput(value);
    debouncedSearch(value);
}

function selectSuggestion(suggestion: Suggestion) {
    inputValue.value = suggestion.name;
    showSuggestions.value = false;
    emit('update:modelValue', suggestion.name);
    emit('search', suggestion.name);
}

function submit() {
    showSuggestions.value = false;
    emit('search', inputValue.value);
}

onBeforeUnmount(() => abortController?.abort());

// Bez małego opóźnienia `@blur` chowałby listę PRZED zarejestrowaniem
// kliknięcia na `<li>` (blur strzela wcześniej niż mousedown->click).
function onBlur() {
    globalThis.setTimeout(() => (showSuggestions.value = false), 150);
}
</script>

<template>
    <div class="relative w-full max-w-xl">
        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                :model-value="inputValue"
                placeholder="Szukaj produktów..."
                class="pl-9"
                @update:model-value="(v) => onType(String(v))"
                @keydown.enter="submit"
                @focus="showSuggestions = true"
                @blur="onBlur"
            />
        </div>

        <ul
            v-if="showSuggestions && suggestions.length"
            class="absolute z-20 mt-1 w-full rounded-md border bg-popover py-1 text-popover-foreground shadow-md"
        >
            <li
                v-for="suggestion in suggestions"
                :key="suggestion.id"
                class="cursor-pointer px-3 py-2 text-sm hover:bg-accent hover:text-accent-foreground"
                @mousedown.prevent="selectSuggestion(suggestion)"
            >
                <span class="font-medium">{{ suggestion.name }}</span>
                <span v-if="suggestion.brand" class="ml-1 text-muted-foreground"
                    >— {{ suggestion.brand }}</span
                >
            </li>
        </ul>
    </div>
</template>
