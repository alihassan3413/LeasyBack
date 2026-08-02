<script setup lang="ts">
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { computed, nextTick, ref, watch } from 'vue';
import MdiChevronDown from '~icons/mdi/chevron-down';
import MdiMagnify from '~icons/mdi/magnify';

export interface SearchableOption {
    value: string;
    label: string;
    description?: string;
    /** Extra text matched by the search box but never rendered. */
    keywords?: string;
}

const props = withDefaults(
    defineProps<{
        modelValue: string;
        options: SearchableOption[];
        placeholder?: string;
        searchPlaceholder?: string;
        emptyLabel?: string;
        disabled?: boolean;
        id?: string;
        describedBy?: string;
        invalid?: boolean;
    }>(),
    {
        placeholder: 'Bitte wählen',
        searchPlaceholder: 'Suchen...',
        emptyLabel: 'Keine Treffer',
        disabled: false,
    },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const open = ref(false);
const search = ref('');
const searchInput = ref<HTMLInputElement | null>(null);

watch(open, (isOpen) => {
    if (!isOpen) {
        search.value = '';

        return;
    }

    void nextTick(() => searchInput.value?.focus());
});

const selected = computed(() => props.options.find((option) => option.value === props.modelValue) ?? null);

const visibleOptions = computed(() => {
    const query = search.value.trim().toLowerCase();

    if (!query) {
        return props.options;
    }

    return props.options.filter((option) =>
        [option.label, option.description ?? '', option.keywords ?? ''].join(' ').toLowerCase().includes(query),
    );
});

function select(option: SearchableOption) {
    emit('update:modelValue', option.value);
    open.value = false;
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child :disabled="disabled">
            <button
                :id="id"
                type="button"
                :aria-invalid="invalid"
                :aria-describedby="describedBy"
                :disabled="disabled"
                class="border-input focus-visible:border-brand-green focus-visible:ring-ring/30 flex h-10 w-full items-center justify-between gap-2 rounded-full border bg-white px-4 text-sm transition-colors focus-visible:ring-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:bg-gray-100 disabled:opacity-60 data-[state=open]:border-[#01B990] aria-[invalid=true]:border-red-400 aria-[invalid=true]:bg-red-50"
            >
                <span class="truncate" :class="selected ? 'text-gray-800' : 'text-gray-400'">
                    {{ selected ? selected.label : placeholder }}
                </span>
                <MdiChevronDown
                    class="shrink-0 text-[20px] text-gray-500 transition-transform duration-200"
                    :class="open ? 'rotate-180' : 'rotate-0'"
                />
            </button>
        </PopoverTrigger>

        <PopoverContent
            align="start"
            :side-offset="6"
            class="w-[var(--reka-popover-trigger-width)] overflow-hidden rounded-2xl border-gray-200 p-0 shadow-[0_12px_34px_rgba(16,57,59,0.16)]"
            @open-auto-focus.prevent
        >
            <div class="border-b border-[#f1f5f5] p-2">
                <div
                    class="flex h-8 items-center gap-2 rounded-full border border-gray-200 bg-gray-50 px-3 transition-colors focus-within:border-[#01B990] focus-within:bg-white"
                >
                    <MdiMagnify class="shrink-0 text-[18px] text-gray-400" />
                    <input
                        ref="searchInput"
                        v-model="search"
                        type="text"
                        :placeholder="searchPlaceholder"
                        class="w-full bg-transparent text-sm outline-none placeholder:text-gray-400"
                    />
                </div>
            </div>

            <div class="max-h-56 overflow-y-auto py-1">
                <p v-if="!visibleOptions.length" class="px-4 py-3 text-sm text-gray-400">
                    {{ emptyLabel }}
                </p>

                <button
                    v-for="option in visibleOptions"
                    :key="option.value"
                    type="button"
                    class="flex w-full flex-col items-start px-4 py-2 text-left transition-colors hover:bg-gray-50"
                    :class="option.value === modelValue ? 'bg-[#01B990]/[0.07]' : ''"
                    @click="select(option)"
                >
                    <span class="text-sm text-gray-800" :class="option.description ? 'font-medium' : ''">{{ option.label }}</span>
                    <span v-if="option.description" class="text-xs text-gray-400">{{ option.description }}</span>
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>
