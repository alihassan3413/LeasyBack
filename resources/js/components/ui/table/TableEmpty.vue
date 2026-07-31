<script setup lang="ts">
import TableCell from '@/components/ui/table/TableCell.vue';
import TableRow from '@/components/ui/table/TableRow.vue';
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = withDefaults(
    defineProps<{
        class?: HTMLAttributes['class'];
        colspan?: number;
    }>(),
    {
        colspan: 1,
    },
);
</script>

<template>
    <TableRow>
        <TableCell :colspan="colspan" :class="cn('text-foreground p-4 align-middle text-sm whitespace-nowrap', props.class)">
            <div class="flex items-center justify-center py-10">
                <slot />
            </div>
        </TableCell>
    </TableRow>
</template>
