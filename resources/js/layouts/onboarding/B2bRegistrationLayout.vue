<script setup lang="ts">
/**
 * Shell for the Firmenkunde (B2B) registration page — deliberately identical
 * to B2cRegistrationLayout (same teal canvas, same decorative paths, same
 * heading and "Später fertigstellen" escape hatch) minus the stepper, since
 * company registration is a single form rather than a three-step wizard.
 */
import { Link } from '@inertiajs/vue3';

withDefaults(
    defineProps<{
        title: string;
        /** Label of the dashboard escape hatch in the top-right corner. */
        backLabel?: string;
    }>(),
    {
        backLabel: 'Später fertigstellen',
    },
);
</script>

<template>
    <main class="bg-brand-teal relative min-h-dvh overflow-hidden">
        <img
            src="/path-green.svg"
            alt=""
            class="pointer-events-none absolute z-0"
            style="top: -8vw; right: -22vw; width: 70vw; height: auto; opacity: 0.65"
        />
        <img src="/path-orange.svg" alt="" class="pointer-events-none absolute z-0" style="top: -18vw; right: -28vw; width: 75vw; height: auto" />
        <img
            src="/path-green.svg"
            alt=""
            class="pointer-events-none absolute z-0"
            style="bottom: -28vw; left: -25vw; width: 75vw; height: auto; opacity: 0.65"
        />
        <img src="/path-orange.svg" alt="" class="pointer-events-none absolute z-0" style="bottom: -18vw; left: -22vw; width: 70vw; height: auto" />

        <div class="relative z-10 mx-auto flex min-h-dvh max-w-[720px] flex-col px-6 pt-12 pb-10 sm:pt-16">
            <div class="flex items-start justify-between gap-4">
                <h1 class="text-2xl leading-tight font-bold text-white md:text-[32px]">{{ title }}</h1>
                <Link :href="route('dashboard')" class="text-brand-orange mt-1 shrink-0 text-sm font-medium hover:underline">
                    {{ backLabel }}
                </Link>
            </div>

            <div class="mt-6 mb-8 space-y-6 sm:mt-8">
                <slot />
            </div>
        </div>
    </main>
</template>
