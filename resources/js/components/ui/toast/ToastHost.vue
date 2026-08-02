<script setup lang="ts">
import ToastItem from '@/components/ui/toast/ToastItem.vue';
import { useToast } from '@/composables/useToast';
import { useFlashToasts } from '@/composables/useFlashToasts';

const { toasts, dismiss } = useToast();

useFlashToasts();
</script>

<template>
    <Teleport to="body">
        <div class="pointer-events-none fixed inset-x-4 bottom-4 z-[9999] flex flex-col items-center gap-2.5 sm:inset-x-auto sm:right-6 sm:bottom-6 sm:items-end">
            <TransitionGroup name="lb-toast">
                <ToastItem v-for="toast in toasts" :key="toast.id" :toast="toast" @dismiss="dismiss" />
            </TransitionGroup>
        </div>
    </Teleport>
</template>

<style scoped>
.lb-toast-enter-active {
    transition:
        opacity 0.22s ease,
        transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);
}

.lb-toast-leave-active {
    transition:
        opacity 0.16s ease,
        transform 0.2s ease;
    position: absolute;
}

.lb-toast-enter-from {
    opacity: 0;
    transform: translateY(14px) scale(0.96);
}

.lb-toast-leave-to {
    opacity: 0;
    transform: translateY(6px) scale(0.97);
}

.lb-toast-move {
    transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);
}
</style>
