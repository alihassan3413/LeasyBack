<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Primitive, type PrimitiveProps } from 'reka-ui';
import { LoaderCircle } from 'lucide-vue-next';
import { computed, type HTMLAttributes } from 'vue';
import { buttonVariants, type ButtonVariants } from '.';

interface Props extends PrimitiveProps {
    variant?: ButtonVariants['variant'];
    size?: ButtonVariants['size'];
    class?: HTMLAttributes['class'];
    disabled?: boolean;
    /** Shows a spinner and blocks re-submission — also implies disabled. */
    loading?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    as: 'button',
});

// `loading` implies disabled (prevents a duplicate submit while a request is
// in flight), independent of any explicit `disabled` the caller also passes.
const isDisabled = computed(() => props.disabled || props.loading);
</script>

<template>
    <Primitive
        :as="as"
        :as-child="asChild"
        :disabled="isDisabled"
        :aria-busy="loading || undefined"
        :class="cn(buttonVariants({ variant, size }), props.class)"
    >
        <LoaderCircle v-if="loading" class="animate-spin" aria-hidden="true" />
        <slot />
    </Primitive>
</template>
