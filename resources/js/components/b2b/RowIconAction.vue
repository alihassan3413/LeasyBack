<script setup lang="ts">
/**
 * Icon-only row action with a tooltip. The label is always present as
 * `aria-label` as well as tooltip text, so the action is never icon-only for
 * assistive tech — and a disabled action still explains itself on hover,
 * which is the whole point when "remove" is greyed out because it would
 * strand the company without an owner.
 */
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        icon: Component;
        label: string;
        /** Shown instead of `label` when the action is unavailable. */
        disabledLabel?: string;
        disabled?: boolean;
        danger?: boolean;
    }>(),
    {
        disabled: false,
        danger: false,
    },
);

defineEmits<{ click: [] }>();
</script>

<template>
    <Tooltip>
        <TooltipTrigger as-child>
            <!--
                Wrapped in a span so the tooltip still fires while the button
                itself is disabled — a disabled button emits no pointer events.
            -->
            <span class="inline-flex">
                <button
                    type="button"
                    class="text-muted-foreground focus-visible:ring-ring inline-flex size-8 items-center justify-center rounded-lg transition-colors focus-visible:ring-2 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-40"
                    :class="danger ? 'hover:bg-destructive/10 hover:text-destructive' : 'hover:bg-muted hover:text-brand-teal'"
                    :disabled="disabled"
                    :aria-label="label"
                    @click="$emit('click')"
                >
                    <component :is="icon" class="size-[18px]" aria-hidden="true" />
                </button>
            </span>
        </TooltipTrigger>

        <TooltipContent>{{ disabled && disabledLabel ? disabledLabel : label }}</TooltipContent>
    </Tooltip>
</template>
