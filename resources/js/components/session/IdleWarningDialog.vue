<script setup lang="ts">
import { useSessionGuard } from '@/composables/useSessionGuard';
import { computed } from 'vue';
import MdiTimerSandComplete from '~icons/mdi/timer-sand-complete';

const { warningVisible, secondsRemaining, countdownProgress, staySignedIn, logout } = useSessionGuard();

const RADIUS = 34;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

const dashOffset = computed(() => CIRCUMFERENCE * (1 - countdownProgress.value));
const urgent = computed(() => secondsRemaining.value <= 10);
</script>

<template>
    <Teleport to="body">
        <Transition name="lb-idle">
            <div v-if="warningVisible" class="fixed inset-0 z-[190] flex items-center justify-center px-4" role="alertdialog" aria-modal="true">
                <div class="absolute inset-0 bg-[#0d3133]/45 backdrop-blur-[3px]"></div>

                <div
                    class="lb-idle-card relative w-full max-w-[380px] overflow-hidden rounded-[22px] border border-white/10 px-7 pt-8 pb-7 text-center"
                    style="background: linear-gradient(180deg, #10393b 0%, #0d3133 100%); box-shadow: 0 24px 60px rgba(16, 57, 59, 0.45)"
                >
                    <div class="relative mx-auto mb-5 size-[84px]">
                        <svg class="size-full -rotate-90" viewBox="0 0 84 84">
                            <circle cx="42" cy="42" :r="RADIUS" fill="none" stroke="rgba(255,255,255,0.09)" stroke-width="5" />
                            <circle
                                cx="42"
                                cy="42"
                                :r="RADIUS"
                                fill="none"
                                :stroke="urgent ? '#E5533D' : '#EF8450'"
                                stroke-width="5"
                                stroke-linecap="round"
                                :stroke-dasharray="CIRCUMFERENCE"
                                :stroke-dashoffset="dashOffset"
                                style="transition: stroke-dashoffset 0.5s linear, stroke 0.3s ease"
                            />
                        </svg>

                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span
                                class="text-[26px] leading-none font-extrabold tabular-nums transition-colors"
                                :class="urgent ? 'text-[#E5533D]' : 'text-white'"
                            >
                                {{ secondsRemaining }}
                            </span>
                            <span class="mt-0.5 text-[10px] font-semibold tracking-wide text-white/35 uppercase">Sek.</span>
                        </div>
                    </div>

                    <div class="mx-auto mb-3 flex size-9 items-center justify-center rounded-[11px] bg-[#EF8450]/15 text-[#EF8450]">
                        <MdiTimerSandComplete class="text-[18px]" />
                    </div>

                    <h2 class="text-[18px] font-extrabold tracking-[-0.2px] text-white">Sind Sie noch da?</h2>
                    <p class="mx-auto mt-2 max-w-[280px] text-[13px] leading-[1.5] text-white/55">
                        Aus Sicherheitsgründen melden wir Sie nach 5 Minuten Inaktivität automatisch ab.
                    </p>

                    <div class="mt-6 flex flex-col gap-2.5">
                        <button
                            type="button"
                            class="h-11 w-full rounded-full bg-[#01B990] text-[14px] font-bold text-white shadow-[0_8px_20px_rgba(1,185,144,0.32)] transition-all hover:brightness-105 active:scale-[0.98]"
                            @click="staySignedIn"
                        >
                            Angemeldet bleiben
                        </button>
                        <button
                            type="button"
                            class="h-11 w-full rounded-full border border-white/15 text-[13.5px] font-semibold text-white/60 transition-colors hover:border-white/30 hover:text-white"
                            @click="logout('manual')"
                        >
                            Jetzt abmelden
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.lb-idle-enter-active {
    transition: opacity 0.2s ease;
}

.lb-idle-leave-active {
    transition: opacity 0.16s ease;
}

.lb-idle-enter-from,
.lb-idle-leave-to {
    opacity: 0;
}

.lb-idle-enter-active .lb-idle-card {
    animation: lb-idle-in 0.34s cubic-bezier(0.22, 1, 0.36, 1);
}

@keyframes lb-idle-in {
    from {
        opacity: 0;
        transform: translateY(16px) scale(0.94);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}
</style>
