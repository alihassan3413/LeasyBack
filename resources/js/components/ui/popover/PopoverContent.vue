<script setup lang="ts">
import { cn } from '@/lib/utils';
import { PopoverContent, PopoverPortal, useForwardPropsEmits, type PopoverContentEmits, type PopoverContentProps } from 'reka-ui';
import { computed, type HTMLAttributes } from 'vue';

const props = withDefaults(defineProps<PopoverContentProps & { class?: HTMLAttributes['class'] }>(), {
    sideOffset: 8,
});
const emits = defineEmits<PopoverContentEmits>();

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;

    return delegated;
});

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
    <PopoverPortal>
        <PopoverContent
            data-slot="popover-content"
            v-bind="forwarded"
            :class="
                cn(
                    'border-brand-green-gray z-50 w-70 rounded-[6px] border bg-white px-4 py-4 shadow-[0_4px_8px_rgba(0,0,0,0.16)] outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2',
                    props.class,
                )
            "
        >
            <slot />
        </PopoverContent>
    </PopoverPortal>
</template>
