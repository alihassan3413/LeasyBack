<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Props {
    href?: string;
    variant?: 'primary' | 'secondary';
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'secondary',
});

defineEmits<{ click: [MouseEvent] }>();

const classes = computed(() =>
    cn(
        'focus-visible:ring-brand-teal inline-flex h-11 items-center justify-center gap-2 rounded-[5px] px-5 text-sm font-bold transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none motion-reduce:transition-none',
        props.variant === 'primary'
            ? 'bg-brand-teal hover:bg-brand-teal/90 text-white'
            : 'border-brand-green-gray text-brand-teal hover:bg-brand-teal/[0.04] border bg-white',
    ),
);
</script>

<template>
    <Link v-if="href" :href="href" :class="classes">
        <slot />
    </Link>
    <button v-else type="button" :class="classes" @click="$emit('click', $event)">
        <slot />
    </button>
</template>
