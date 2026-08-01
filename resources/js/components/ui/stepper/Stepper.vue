<script setup lang="ts">
/**
 * Generic numbered-step progress indicator for multi-step flows (currently
 * the B2C onboarding wizard, reusable for any future one). Renders on dark
 * backgrounds by design (brand-green fill against a translucent white
 * track) — pass `class` to adjust spacing from the caller.
 */
import { cn } from '@/lib/utils';
import { computed, type HTMLAttributes } from 'vue';

const props = withDefaults(
    defineProps<{
        steps: number;
        current: number;
        class?: HTMLAttributes['class'];
    }>(),
    {
        steps: 3,
    },
);

const progressScale = computed(() => {
    if (props.steps <= 1) {
        return 0;
    }

    const clampedCurrent = Math.min(Math.max(props.current, 1), props.steps);

    return (clampedCurrent - 1) / (props.steps - 1);
});
</script>

<template>
    <div
        :class="cn('relative flex w-full items-center justify-between', props.class)"
        role="progressbar"
        :aria-valuemin="1"
        :aria-valuemax="steps"
        :aria-valuenow="current"
    >
        <div class="absolute inset-x-2.5 h-1.5 rounded-full bg-white/25" />
        <div
            class="absolute inset-x-2.5 h-1.5 origin-left rounded-full bg-brand-green transition-transform duration-300"
            :style="{ transform: `scaleX(${progressScale})` }"
        />

        <div
            v-for="step in steps"
            :key="step"
            class="relative z-10 flex size-5 items-center justify-center rounded-full transition-colors duration-300"
            :class="step <= current ? 'bg-brand-green' : 'bg-white/25'"
        >
            <div v-if="step === current" class="size-1.5 rounded-full bg-white" />
        </div>
    </div>
</template>
