<script setup lang="ts">
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { VEHICLE_STATUS_FILTER_OPTIONS } from '@/lib/vehicleStatus';
import { computed } from 'vue';
import MdiClose from '~icons/mdi/close';
import MdiFilterVariant from '~icons/mdi/filter-variant';
import MdiMagnify from '~icons/mdi/magnify';

const props = defineProps<{
    search: string;
    status: string;
}>();

const emit = defineEmits<{
    (e: 'update:search', value: string): void;
    (e: 'update:status', value: string): void;
    (e: 'reset'): void;
}>();

const activeFilterCount = computed(() => (props.status ? 1 : 0));

const activeStatusLabel = computed(
    () => VEHICLE_STATUS_FILTER_OPTIONS.find((option) => option.value === props.status)?.label ?? '',
);
</script>

<template>
    <div class="flex w-full items-center gap-2">
        <div
            class="flex h-10 min-w-0 flex-1 items-center gap-2 rounded-full border border-[#d8e4e3] bg-white px-4 transition focus-within:border-[#01B990] md:max-w-[420px]"
        >
            <MdiMagnify class="shrink-0 text-[20px] text-[#6f8585]" />
            <input
                :value="search"
                type="search"
                placeholder="Fahrzeug suchen — Kennzeichen, Marke, Modell, FIN…"
                class="min-w-0 flex-1 bg-transparent text-sm text-[#10393b] outline-none placeholder:text-[#9aacac] [&::-webkit-search-cancel-button]:hidden"
                @input="emit('update:search', ($event.target as HTMLInputElement).value)"
            />
            <button
                v-if="search"
                type="button"
                aria-label="Suche zurücksetzen"
                class="shrink-0 text-[#9aacac] transition-colors hover:text-[#10393b]"
                @click="emit('update:search', '')"
            >
                <MdiClose class="text-[18px]" />
            </button>
        </div>

        <Popover>
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="flex h-10 shrink-0 items-center gap-2 rounded-full border border-[#d8e4e3] bg-white px-4 text-sm font-medium text-[#10393b] transition hover:border-[#01B990]"
                    :class="activeFilterCount ? 'border-[#01B990]' : ''"
                >
                    <MdiFilterVariant class="text-[18px] text-[#6f8585]" />
                    <span class="hidden sm:inline">{{ activeStatusLabel || 'Filter' }}</span>
                    <span
                        v-if="activeFilterCount"
                        class="flex h-5 min-w-5 items-center justify-center rounded-full bg-[#01B990] px-1.5 text-[11px] font-bold text-white"
                    >
                        {{ activeFilterCount }}
                    </span>
                </button>
            </PopoverTrigger>

            <PopoverContent align="end" class="w-64 rounded-2xl p-0">
                <div class="flex items-center justify-between px-4 py-3">
                    <span class="text-sm font-bold text-[#10393b]">Status</span>
                    <button
                        v-if="activeFilterCount"
                        type="button"
                        class="text-xs font-semibold text-[#01B990] hover:underline"
                        @click="emit('reset')"
                    >
                        Zurücksetzen
                    </button>
                </div>

                <div class="max-h-64 overflow-y-auto pb-2">
                    <button
                        v-for="option in VEHICLE_STATUS_FILTER_OPTIONS"
                        :key="option.value"
                        type="button"
                        class="flex w-full items-center justify-between px-4 py-2 text-left text-sm transition hover:bg-gray-50"
                        :class="option.value === status ? 'font-semibold text-[#10393b]' : 'text-gray-600'"
                        @click="emit('update:status', option.value === status ? '' : option.value)"
                    >
                        <span>{{ option.label }}</span>
                        <span v-if="option.value === status" class="size-2 rounded-full bg-[#01B990]"></span>
                    </button>
                </div>
            </PopoverContent>
        </Popover>
    </div>
</template>
