<script setup lang="ts">
import { cn } from '@/lib/utils';
import { reactiveOmit } from '@vueuse/core';
import { ChevronDown } from 'lucide-vue-next';
import { SelectIcon, SelectTrigger, useForwardProps, type SelectTriggerProps } from 'reka-ui';
import type { HTMLAttributes } from 'vue';

const props = withDefaults(defineProps<SelectTriggerProps & { class?: HTMLAttributes['class']; size?: 'sm' | 'default' }>(), {
    size: 'default',
});

const delegatedProps = reactiveOmit(props, 'class', 'size');
const forwardedProps = useForwardProps(delegatedProps);
</script>

<template>
    <SelectTrigger
        data-slot="select-trigger"
        :data-size="size"
        v-bind="forwardedProps"
        :class="
            cn(
                'border-input data-[placeholder]:text-muted-foreground focus-visible:outline-hidden flex h-10 w-full items-center justify-between gap-2 rounded-full border bg-background px-4 py-2 text-sm ring-offset-background transition-colors focus-visible:border-brand-green focus-visible:ring-2 focus-visible:ring-ring/30 focus-visible:ring-offset-0 data-[state=open]:border-brand-green disabled:cursor-not-allowed disabled:opacity-50 data-[size=sm]:h-8 aria-[invalid=true]:border-destructive aria-[invalid=true]:focus-visible:border-destructive aria-[invalid=true]:focus-visible:ring-destructive/30 [&>span]:line-clamp-1',
                props.class,
            )
        "
    >
        <slot />
        <SelectIcon as-child>
            <ChevronDown class="text-muted-foreground size-4 shrink-0 opacity-50" aria-hidden="true" />
        </SelectIcon>
    </SelectTrigger>
</template>
