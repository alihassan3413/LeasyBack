<script setup lang="ts">
import { TableCell, TableRow } from '@/components/ui/table';

defineProps<{ embedded?: boolean }>();
</script>

<template>
    <TableRow v-if="!embedded" class="hidden border-0 hover:bg-transparent md:table-row">
        <TableCell colspan="12" class="max-w-0 overflow-x-auto p-0 whitespace-normal">
            <slot />
        </TableCell>
    </TableRow>

    <div v-else class="overflow-x-auto">
        <slot />
    </div>
</template>
