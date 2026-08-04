<script setup lang="ts">
import ErrorPanel from '@/components/errors/ErrorPanel.vue';
import ErrorShell from '@/components/errors/ErrorShell.vue';
import { resolveErrorCopy } from '@/lib/errorPages';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    status: number;
    maintenanceMessage?: string | null;
}>();

const title = computed(() => `${props.status} — ${resolveErrorCopy(props.status).eyebrow}`);
</script>

<template>
    <Head :title="title" />

    <ErrorShell>
        <ErrorPanel :status="status" :maintenance-message="maintenanceMessage" />
    </ErrorShell>
</template>
