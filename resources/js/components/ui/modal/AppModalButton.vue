<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        type?: 'button' | 'submit';
        variant?: 'primary' | 'secondary';
        disabled?: boolean;
        block?: boolean;
    }>(),
    {
        type: 'button',
        variant: 'primary',
        disabled: false,
        block: false,
    },
);

const classes = computed(() => [
    'h-9 px-6 rounded-full text-base font-semibold text-white transition-all duration-200 shadow-lg disabled:cursor-not-allowed',
    props.block ? 'w-full' : 'w-full md:w-auto',
    props.variant === 'secondary' ? 'bg-emerald-500 hover:opacity-90' : '',
]);

const style = computed(() => {
    if (props.variant === 'secondary') {
        return undefined;
    }

    return props.disabled ? 'background: #D9D9D9;' : 'background: #EF8450;';
});
</script>

<template>
    <button :type="type" :class="classes" :style="style" :disabled="disabled">
        <slot />
    </button>
</template>
