<script setup lang="ts">
import SearchableSelectField, { type SearchableOption } from '@/components/form/SearchableSelectField.vue';
import type { StationData } from '@/types/order';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        stations: StationData[];
        placeholder?: string;
        disabled?: boolean;
        id?: string;
        describedBy?: string;
        invalid?: boolean;
    }>(),
    {
        placeholder: 'Station wählen',
        disabled: false,
    },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const options = computed<SearchableOption[]>(() =>
    props.stations.map((station) => ({
        value: station.station_id,
        label: `${station.name} — ${station.ort}`,
        description: `${station.strasse}, ${station.plz} ${station.ort}`,
        keywords: `${station.bundesland ?? ''} ${station.provider}`,
    })),
);
</script>

<template>
    <SearchableSelectField
        :id="id"
        :model-value="modelValue"
        :options="options"
        :placeholder="placeholder"
        search-placeholder="Station suchen..."
        empty-label="Keine Stationen gefunden"
        :disabled="disabled"
        :described-by="describedBy"
        :invalid="invalid"
        @update:model-value="(value) => emit('update:modelValue', value)"
    />
</template>
