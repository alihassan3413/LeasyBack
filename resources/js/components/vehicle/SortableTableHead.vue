<script setup lang="ts">
import { TableHead } from '@/components/ui/table';
import { computed, type HTMLAttributes } from 'vue';
import MdiArrowDown from '~icons/mdi/arrow-down';
import MdiArrowUp from '~icons/mdi/arrow-up';
import MdiUnfoldMoreHorizontal from '~icons/mdi/unfold-more-horizontal';

const props = defineProps<{
    column: string;
    sort: string;
    direction: string;
    align?: 'left' | 'right';
    class?: HTMLAttributes['class'];
}>();

const emit = defineEmits<{ (e: 'sort', column: string): void }>();

const isActive = computed(() => props.sort === props.column);
const ariaSort = computed(() => (isActive.value ? (props.direction === 'asc' ? 'ascending' : 'descending') : 'none'));
</script>

<template>
    <TableHead :class="props.class" :aria-sort="ariaSort">
        <button
            type="button"
            class="group flex w-full items-center gap-1 text-[13px] font-medium text-white transition-opacity hover:opacity-80"
            :class="align === 'right' ? 'justify-end' : 'justify-start'"
            @click="emit('sort', column)"
        >
            <span><slot /></span>
            <MdiArrowUp v-if="isActive && direction === 'asc'" class="text-[16px]" />
            <MdiArrowDown v-else-if="isActive" class="text-[16px]" />
            <MdiUnfoldMoreHorizontal v-else class="text-[16px] opacity-40 transition-opacity group-hover:opacity-80" />
        </button>
    </TableHead>
</template>
