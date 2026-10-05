<script setup lang="ts">
import AuthLayout from '@/layouts/auth/AuthSplitLayout.vue';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <AuthLayout :title="title" :description="description">
        <slot />
        <template v-if="$slots.after" #after><slot name="after" /></template>
    </AuthLayout>
</template>
