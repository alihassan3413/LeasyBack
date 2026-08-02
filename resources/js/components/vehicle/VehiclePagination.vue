<script setup lang="ts">
import { computed } from 'vue';
import MdiChevronLeft from '~icons/mdi/chevron-left';
import MdiChevronRight from '~icons/mdi/chevron-right';

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{ meta: PaginationMeta }>();
const emit = defineEmits<{ (e: 'change', page: number): void }>();

const pages = computed<(number | '…')[]>(() => {
    const { current_page: current, last_page: last } = props.meta;

    if (last <= 7) {
        return Array.from({ length: last }, (_, index) => index + 1);
    }

    const window = new Set([1, last, current, current - 1, current + 1]);

    if (current <= 3) {
        [2, 3, 4].forEach((page) => window.add(page));
    }

    if (current >= last - 2) {
        [last - 3, last - 2, last - 1].forEach((page) => window.add(page));
    }

    const sorted = [...window].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b);
    const result: (number | '…')[] = [];

    sorted.forEach((page, index) => {
        if (index > 0 && page - (sorted[index - 1] as number) > 1) {
            result.push('…');
        }

        result.push(page);
    });

    return result;
});

function go(page: number) {
    if (page >= 1 && page <= props.meta.last_page && page !== props.meta.current_page) {
        emit('change', page);
    }
}
</script>

<template>
    <div v-if="meta.total > 0" class="flex flex-col items-center justify-between gap-3 pt-4 sm:flex-row">
        <p class="text-[12.5px] text-[#00000080]">
            <span class="font-semibold text-[#10393b]">{{ meta.from }}–{{ meta.to }}</span>
            von {{ meta.total }} {{ meta.total === 1 ? 'Fahrzeug' : 'Fahrzeugen' }}
        </p>

        <nav v-if="meta.last_page > 1" class="flex items-center gap-1">
            <button
                type="button"
                aria-label="Vorherige Seite"
                class="flex size-8 items-center justify-center rounded-full border border-[#d8e4e3] text-[#6f8585] transition hover:border-[#01B990] hover:text-[#10393b] disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:border-[#d8e4e3]"
                :disabled="meta.current_page === 1"
                @click="go(meta.current_page - 1)"
            >
                <MdiChevronLeft class="text-[18px]" />
            </button>

            <template v-for="(page, index) in pages" :key="`${page}-${index}`">
                <span v-if="page === '…'" class="px-1 text-[12.5px] text-[#9aacac]">…</span>
                <button
                    v-else
                    type="button"
                    class="h-8 min-w-8 rounded-full px-2 text-[12.5px] font-semibold transition"
                    :class="
                        page === meta.current_page
                            ? 'bg-[#01B990] text-white shadow-[0_4px_12px_rgba(1,185,144,0.28)]'
                            : 'border border-[#d8e4e3] text-[#6f8585] hover:border-[#01B990] hover:text-[#10393b]'
                    "
                    @click="go(page as number)"
                >
                    {{ page }}
                </button>
            </template>

            <button
                type="button"
                aria-label="Nächste Seite"
                class="flex size-8 items-center justify-center rounded-full border border-[#d8e4e3] text-[#6f8585] transition hover:border-[#01B990] hover:text-[#10393b] disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:border-[#d8e4e3]"
                :disabled="meta.current_page === meta.last_page"
                @click="go(meta.current_page + 1)"
            >
                <MdiChevronRight class="text-[18px]" />
            </button>
        </nav>
    </div>
</template>
