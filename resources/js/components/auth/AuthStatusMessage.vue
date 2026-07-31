<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed, type HTMLAttributes } from 'vue';

interface Props {
    variant?: 'success' | 'error' | 'info';
    class?: HTMLAttributes['class'];
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'success',
});

// Errors interrupt (assertive); success/info confirmations don't need to.
const isAssertive = computed(() => props.variant === 'error');

const variantClasses: Record<NonNullable<Props['variant']>, string> = {
    success: 'text-green-600 dark:text-green-500',
    error: 'text-destructive',
    info: 'text-muted-foreground',
};
</script>

<template>
    <div
        :role="isAssertive ? 'alert' : 'status'"
        :aria-live="isAssertive ? 'assertive' : 'polite'"
        :class="cn('mb-4 text-center text-sm font-medium', variantClasses[variant], props.class)"
    >
        <slot />
    </div>
</template>
