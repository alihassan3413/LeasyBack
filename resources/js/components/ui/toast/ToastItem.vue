<script setup lang="ts">
import type { ToastItem, ToastVariant } from '@/composables/useToast';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import MdiAlertCircleOutline from '~icons/mdi/alert-circle-outline';
import MdiAlertOutline from '~icons/mdi/alert-outline';
import MdiCheck from '~icons/mdi/check';
import MdiClose from '~icons/mdi/close';
import MdiInformationOutline from '~icons/mdi/information-outline';

const props = defineProps<{ toast: ToastItem }>();
const emit = defineEmits<{ (e: 'dismiss', id: number): void }>();

const ACCENT: Record<ToastVariant, string> = {
    success: '#01B990',
    error: '#E5533D',
    warning: '#EF8450',
    info: '#4FA3A6',
};

const ICON = {
    success: MdiCheck,
    error: MdiAlertCircleOutline,
    warning: MdiAlertOutline,
    info: MdiInformationOutline,
};

const accent = computed(() => ACCENT[props.toast.variant]);
const icon = computed(() => ICON[props.toast.variant]);

const paused = ref(false);

let timer: ReturnType<typeof setTimeout> | null = null;
let startedAt = 0;
let remaining = props.toast.duration;

function start() {
    startedAt = performance.now();
    timer = setTimeout(() => emit('dismiss', props.toast.id), remaining);
}

function stop() {
    if (timer) {
        clearTimeout(timer);
        timer = null;
        remaining -= performance.now() - startedAt;
    }
}

function onEnter() {
    paused.value = true;
    stop();
}

function onLeave() {
    paused.value = false;

    if (remaining > 0) {
        start();
    }
}

onMounted(start);
onBeforeUnmount(stop);
</script>

<template>
    <div
        role="status"
        aria-live="polite"
        class="pointer-events-auto relative w-[340px] max-w-[calc(100vw-2rem)] overflow-hidden rounded-[16px] border border-white/10"
        style="background: linear-gradient(180deg, #10393b 0%, #0d3133 100%); box-shadow: 0 12px 34px rgba(16, 57, 59, 0.28)"
        @mouseenter="onEnter"
        @mouseleave="onLeave"
    >
        <div class="flex items-start gap-3 p-3.5 pr-2.5">
            <span
                class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-[11px] text-white"
                :style="{ backgroundColor: accent }"
            >
                <component :is="icon" class="text-[17px]" />
            </span>

            <div class="min-w-0 flex-1 pt-0.5">
                <p class="text-[13.5px] leading-snug font-bold text-white">{{ toast.title }}</p>
                <p v-if="toast.description" class="mt-0.5 text-[12px] leading-[1.45] text-white/55">
                    {{ toast.description }}
                </p>
            </div>

            <button
                type="button"
                aria-label="Schließen"
                class="mt-0.5 shrink-0 rounded-full p-1 text-white/35 transition-colors hover:bg-white/10 hover:text-white/80"
                @click="emit('dismiss', toast.id)"
            >
                <MdiClose class="text-[15px]" />
            </button>
        </div>

        <span
            class="lb-toast-progress absolute bottom-0 left-0 h-[2px]"
            :style="{
                backgroundColor: accent,
                animationDuration: `${toast.duration}ms`,
                animationPlayState: paused ? 'paused' : 'running',
            }"
        ></span>
    </div>
</template>

<style scoped>
.lb-toast-progress {
    width: 100%;
    transform-origin: left center;
    animation-name: lb-toast-drain;
    animation-timing-function: linear;
    animation-fill-mode: forwards;
}

@keyframes lb-toast-drain {
    from {
        transform: scaleX(1);
    }
    to {
        transform: scaleX(0);
    }
}
</style>
