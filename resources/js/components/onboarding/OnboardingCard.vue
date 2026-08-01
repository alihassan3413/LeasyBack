<script setup lang="ts">
/**
 * Shared white card shell for each onboarding step — title, optional
 * description, a divider, then the step's own content via the default slot.
 * Keeps every step visually identical without repeating the same
 * card/heading markup three times.
 */
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div class="w-full rounded-[10px] bg-white px-6 py-5 shadow-[0_4px_4px_rgba(0,0,0,0.25)] md:px-8 md:py-6">
        <h2 class="text-brand-teal text-xl font-bold">{{ title }}</h2>
        <p v-if="description" class="text-muted-foreground mt-1 text-sm">{{ description }}</p>

        <div class="bg-brand-green-gray mt-2 mb-3 h-px w-full" />

        <slot />
    </div>
</template>
