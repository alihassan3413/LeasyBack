<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { useGooglePlaces, type PlaceSuggestion, type ResolvedPlaceAddress } from '@/composables/useGooglePlaces';
import { onBeforeUnmount, ref, useId } from 'vue';

withDefaults(
    defineProps<{
        modelValue: string;
        id?: string;
        placeholder?: string;
        disabled?: boolean;
        invalid?: boolean;
        describedBy?: string;
        class?: string;
    }>(),
    {
        placeholder: 'Straße eingeben…',
        disabled: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    resolved: [address: ResolvedPlaceAddress];
}>();

const { autocomplete, getPlaceDetails } = useGooglePlaces();

const suggestions = ref<PlaceSuggestion[]>([]);
const open = ref(false);
const loading = ref(false);
const activeIndex = ref(-1);
const autoId = useId();

let debounceTimer: ReturnType<typeof setTimeout> | null = null;

function runSearch(query: string) {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }

    if (query.trim().length < 3) {
        suggestions.value = [];
        open.value = false;
        loading.value = false;

        return;
    }

    loading.value = true;
    debounceTimer = setTimeout(async () => {
        suggestions.value = await autocomplete(query);
        activeIndex.value = -1;
        open.value = suggestions.value.length > 0;
        loading.value = false;
    }, 300);
}

function onInput(next: string | number) {
    const text = String(next ?? '');
    emit('update:modelValue', text);
    runSearch(text);
}

async function selectSuggestion(suggestion: PlaceSuggestion) {
    open.value = false;
    suggestions.value = [];
    emit('update:modelValue', suggestion.mainText);

    const resolved = await getPlaceDetails(suggestion.placeId);

    if (resolved) {
        emit('resolved', resolved);
    }
}

function onKeydown(event: KeyboardEvent) {
    if (!open.value || suggestions.value.length === 0) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = (activeIndex.value + 1) % suggestions.value.length;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = (activeIndex.value - 1 + suggestions.value.length) % suggestions.value.length;
    } else if (event.key === 'Enter') {
        if (activeIndex.value >= 0) {
            event.preventDefault();
            selectSuggestion(suggestions.value[activeIndex.value]);
        }
    } else if (event.key === 'Escape') {
        open.value = false;
    }
}

function onBlur() {
    setTimeout(() => {
        open.value = false;
    }, 150);
}

onBeforeUnmount(() => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
});
</script>

<template>
    <div class="relative">
        <Input
            :id="id ?? autoId"
            :model-value="modelValue"
            :placeholder="placeholder"
            :disabled="disabled"
            :aria-invalid="invalid"
            :aria-describedby="describedBy"
            :aria-expanded="open"
            aria-autocomplete="list"
            autocomplete="off"
            role="combobox"
            :class="['pr-9', $props.class]"
            @update:model-value="onInput"
            @keydown="onKeydown"
            @focus="suggestions.length && (open = true)"
            @blur="onBlur"
        />
        <IconMdiLoading v-if="loading" class="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-[#9CB3B4]" aria-hidden="true" />
        <IconMdiMapMarkerOutline v-else class="absolute top-1/2 right-3 size-4 -translate-y-1/2 text-[#9CB3B4]" aria-hidden="true" />

        <ul
            v-if="open && suggestions.length"
            class="absolute z-[1000] mt-1 max-h-64 w-full overflow-auto rounded-lg border border-[#D1DCDC] bg-white py-1 shadow-lg"
            role="listbox"
        >
            <li
                v-for="(suggestion, index) in suggestions"
                :key="suggestion.placeId"
                role="option"
                :aria-selected="index === activeIndex"
                :class="['cursor-pointer px-3 py-2 text-sm transition-colors', index === activeIndex ? 'bg-[#F0FBF8]' : 'hover:bg-[#F0FBF8]']"
                @mousedown.prevent="selectSuggestion(suggestion)"
                @mouseenter="activeIndex = index"
            >
                <div class="flex items-start gap-2">
                    <IconMdiMapMarkerOutline class="text-brand-green mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-[#10393B]">{{ suggestion.mainText }}</p>
                        <p v-if="suggestion.secondaryText" class="truncate text-xs text-[#7A9699]">{{ suggestion.secondaryText }}</p>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
