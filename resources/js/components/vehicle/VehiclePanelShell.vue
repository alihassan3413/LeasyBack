<script setup lang="ts">
import { TableCell, TableRow } from '@/components/ui/table';

withDefaults(
    defineProps<{
        embedded?: boolean;
        /** Column count of the table the panel is expanded inside. A colspan
         * larger than the real column count makes the browser widen the table
         * with phantom empty columns, leaving white space on its right side. */
        colspan?: number;
    }>(),
    { embedded: false, colspan: 5 },
);
</script>

<template>
    <TableRow v-if="!embedded" class="hidden border-0 hover:bg-transparent md:table-row">
        <TableCell :colspan="colspan" class="max-w-0 overflow-x-auto p-0 whitespace-normal">
            <slot />
        </TableCell>
    </TableRow>

    <div v-else class="overflow-x-auto">
        <slot />
    </div>
</template>
