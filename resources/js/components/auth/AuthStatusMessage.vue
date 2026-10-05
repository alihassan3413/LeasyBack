<script setup lang="ts">
import { cn } from '@/lib/utils';
import { CircleAlert, CircleCheck, Info } from 'lucide-vue-next';
import { computed, type HTMLAttributes } from 'vue';

interface Props {
    variant?: 'success' | 'error' | 'info';
    id?: string;
    class?: HTMLAttributes['class'];
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'success',
});

// Errors interrupt (assertive); success/info confirmations don't need to.
const isAssertive = computed(() => props.variant === 'error');

// Text stays brand-teal (or red-600 for errors, ≥4.5:1); the coloured icon is
// decorative, the message itself carries the meaning.
const variantClasses: Record<NonNullable<Props['variant']>, string> = {
    success: 'border-brand-green text-brand-teal [&>svg]:text-brand-green',
    error: 'border-red-600 text-red-600',
    info: 'border-brand-teal/15 text-brand-teal [&>svg]:text-brand-teal/70',
};

const icon = computed(() => ({ success: CircleCheck, error: CircleAlert, info: Info })[props.variant]);
</script>

<template>
    <div
        :id="id"
        :role="isAssertive ? 'alert' : 'status'"
        :aria-live="isAssertive ? 'assertive' : 'polite'"
        :class="
            cn('mb-6 flex items-start gap-3 rounded-[6px] border bg-white px-4 py-3 text-sm leading-normal', variantClasses[variant], props.class)
        "
    >
        <component :is="icon" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <div><slot /></div>
    </div>
</template>
