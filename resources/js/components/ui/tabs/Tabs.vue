<script setup lang="ts">
import { cn } from '@/lib/utils';
import { TabsRoot, useForwardPropsEmits, type TabsRootEmits, type TabsRootProps } from 'reka-ui';
import { computed, type HTMLAttributes } from 'vue';

const props = defineProps<TabsRootProps & { class?: HTMLAttributes['class'] }>();
const emits = defineEmits<TabsRootEmits>();

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;

    return delegated;
});

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
    <TabsRoot v-bind="forwarded" :class="cn('flex flex-col gap-2', props.class)">
        <slot />
    </TabsRoot>
</template>
