<script setup lang="ts">
import { onClickOutside } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
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

const root = ref<HTMLElement | null>(null);
const open = ref(false);
const search = ref('');

onClickOutside(root, () => (open.value = false));

watch(open, (isOpen) => {
    if (!isOpen) {
        search.value = '';
    }
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

function toggle() {
    if (props.disabled) {
        return;
    }

    open.value = !open.value;
}

function select(option: SearchableOption) {
    emit('update:modelValue', option.value);
    open.value = false;
}
</script>

<template>
    <div ref="root" class="relative flex w-full flex-col gap-1">
        <div
            :id="id"
            role="combobox"
            :aria-expanded="open"
            :aria-invalid="invalid"
            :aria-describedby="describedBy"
            :tabindex="disabled ? -1 : 0"
            class="border-input flex h-10 w-full items-center justify-between rounded-full border bg-white px-4 outline-none transition focus:border-emerald-500"
            :class="[disabled ? 'cursor-not-allowed bg-gray-100' : 'cursor-pointer', invalid ? 'border-red-400 bg-red-50' : '']"
            @click="toggle"
            @keydown.enter.prevent="toggle"
            @keydown.space.prevent="toggle"
        >
            <span class="truncate text-sm" :class="selected ? 'text-gray-800' : 'text-gray-400'">
                {{ selected ? selected.label : placeholder }}
            </span>
            <MdiChevronDown class="shrink-0 text-[24px] text-gray-500 transition-transform duration-200" :class="open ? 'rotate-180' : 'rotate-0'" />
        </div>

        <div v-if="open" class="absolute top-full z-[10000] mt-1 max-h-56 w-full overflow-y-auto rounded-2xl border border-gray-200 bg-white shadow-lg">
            <div class="sticky top-0 z-10 bg-white p-2">
                <div
                    class="flex h-8 items-center gap-2 rounded-full border border-gray-200 bg-gray-50 px-3 focus-within:border-emerald-500 focus-within:bg-white"
                >
                    <MdiMagnify class="shrink-0 text-[18px] text-gray-400" />
                    <input
                        v-model="search"
                        type="text"
                        :placeholder="searchPlaceholder"
                        class="w-full bg-transparent text-sm outline-none placeholder:text-gray-400"
                        @click.stop
                    />
                </div>
            </div>

            <div v-if="!visibleOptions.length" class="px-4 py-2 text-sm text-gray-400">
                {{ emptyLabel }}
            </div>

            <div
                v-for="option in visibleOptions"
                :key="option.value"
                class="flex cursor-pointer flex-col px-4 py-2 hover:bg-gray-50"
                :class="option.value === modelValue ? 'bg-gray-50' : ''"
                @click="select(option)"
            >
                <span class="text-sm text-gray-800" :class="option.description ? 'font-medium' : ''">{{ option.label }}</span>
                <span v-if="option.description" class="text-xs text-gray-400">{{ option.description }}</span>
            </div>
        </div>
    </div>
</template>
