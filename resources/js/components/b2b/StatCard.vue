<script setup lang="ts">
/**
 * Small headline figure for the top of a page. Deliberately flat — border and
 * a soft shadow only, no fill or gradient — so a row of them reads as one
 * calm band rather than competing blocks of colour.
 */
import type { Component } from 'vue';

defineProps<{
    label: string;
    value: number | string;
    icon: Component;
}>();
</script>

<template>
    <div class="border-border bg-card flex items-center gap-3 rounded-xl border p-4 shadow-sm">
        <span class="bg-brand-green/10 text-brand-teal flex size-10 shrink-0 items-center justify-center rounded-lg">
            <component :is="icon" class="size-5" aria-hidden="true" />
        </span>

        <span class="min-w-0">
            <span class="text-brand-teal block text-xl leading-tight font-semibold tabular-nums">{{ value }}</span>
            <span class="text-muted-foreground block truncate text-xs">{{ label }}</span>
        </span>
    </div>
</template>
