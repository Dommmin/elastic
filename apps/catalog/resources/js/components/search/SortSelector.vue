<script setup lang="ts">
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { SortOption } from '@/types/search';

const model = defineModel<SortOption>({ required: true });

const options: Array<{ value: SortOption; label: string }> = [
    { value: 'relevance', label: 'Trafność' },
    { value: 'price_asc', label: 'Cena: od najniższej' },
    { value: 'price_desc', label: 'Cena: od najwyższej' },
    { value: 'newest', label: 'Najnowsze' },
];
</script>

<template>
    <Select
        :model-value="model"
        @update:model-value="(v) => (model = v as SortOption)"
    >
        <SelectTrigger class="w-[220px]">
            <SelectValue placeholder="Sortuj" />
        </SelectTrigger>
        <SelectContent>
            <SelectItem
                v-for="option in options"
                :key="option.value"
                :value="option.value"
            >
                {{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
