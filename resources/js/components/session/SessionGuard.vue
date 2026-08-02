<script setup lang="ts">
import IdleWarningDialog from '@/components/session/IdleWarningDialog.vue';
import LogoutOverlay from '@/components/session/LogoutOverlay.vue';
import { useSessionGuard } from '@/composables/useSessionGuard';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, watch } from 'vue';

const page = usePage<SharedData>();
const { start, stop, recordActivity, showOverlay } = useSessionGuard();

const authenticated = computed(() => Boolean(page.props.auth?.user));

watch(
    authenticated,
    (isAuthenticated) => {
        if (isAuthenticated) {
            start();

            return;
        }

        stop();
    },
    { immediate: true },
);

const stopNavigation = router.on('navigate', () => recordActivity());

const stopInvalid = router.on('invalid', (event) => {
    if (event.detail.response?.status === 419 && authenticated.value) {
        event.preventDefault();
        showOverlay('session-expired');
        window.setTimeout(() => window.location.assign(route('login')), 1600);
    }
});

onBeforeUnmount(() => {
    stopNavigation();
    stopInvalid();
    stop();
});
</script>

<template>
    <IdleWarningDialog />
    <LogoutOverlay />
</template>
