<script setup lang="ts">
import { useSessionGuard, type LogoutReason } from '@/composables/useSessionGuard';
import { computed } from 'vue';
import MdiCheck from '~icons/mdi/check';

const { overlay } = useSessionGuard();

const COPY: Record<LogoutReason, { title: string; description: string }> = {
    manual: { title: 'Sie werden abgemeldet', description: 'Bis bald bei LeasyBack.' },
    inactivity: {
        title: 'Aus Sicherheitsgründen abgemeldet',
        description: 'Sie waren 6 Minuten inaktiv. Melden Sie sich erneut an, um fortzufahren.',
    },
    'session-expired': {
        title: 'Sitzung abgelaufen',
        description: 'Bitte melden Sie sich erneut an.',
    },
};

const copy = computed(() => COPY[overlay.value.reason]);
const done = computed(() => overlay.value.phase === 'done');
</script>

<template>
    <Teleport to="body">
        <Transition name="lb-logout">
            <div
                v-if="overlay.visible"
                class="fixed inset-0 z-[220] flex items-center justify-center overflow-hidden px-6"
                style="
                    background:
                        radial-gradient(900px 520px at 80% -10%, rgba(1, 185, 144, 0.16), transparent 58%),
                        radial-gradient(700px 460px at -5% 105%, rgba(1, 185, 144, 0.1), transparent 55%),
                        linear-gradient(180deg, #10393b 0%, #0d3133 100%);
                "
                role="alert"
                aria-live="assertive"
            >
                <img
                    src="/path-green.svg"
                    alt=""
                    class="pointer-events-none absolute -top-[14vw] -right-[18vw] w-[62vw] opacity-40"
                    aria-hidden="true"
                />
                <img
                    src="/path-orange.svg"
                    alt=""
                    class="pointer-events-none absolute -bottom-[18vw] -left-[16vw] w-[58vw] opacity-25"
                    aria-hidden="true"
                />

                <div class="lb-logout-stack relative flex flex-col items-center gap-6 text-center">
                    <div class="relative flex size-[104px] items-center justify-center">
                        <span class="absolute inset-0 rounded-full bg-[#01B990]/10"></span>
                        <span v-if="!done" class="lb-pulse absolute inset-0 rounded-full border border-[#01B990]/25"></span>

                        <svg v-if="!done" class="absolute inset-0 size-full -rotate-90" viewBox="0 0 104 104">
                            <circle cx="52" cy="52" r="46" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="3" />
                            <circle
                                class="lb-spin-arc"
                                cx="52"
                                cy="52"
                                r="46"
                                fill="none"
                                stroke="#01B990"
                                stroke-width="3"
                                stroke-linecap="round"
                            />
                        </svg>

                        <span
                            v-else
                            class="lb-check flex size-[72px] items-center justify-center rounded-full bg-[#01B990] text-white shadow-[0_10px_30px_rgba(1,185,144,0.4)]"
                        >
                            <MdiCheck class="text-[34px]" />
                        </span>

                        <img v-if="!done" src="/leasyback-logo.svg" alt="" class="relative w-[46px] opacity-90" aria-hidden="true" />
                    </div>

                    <div class="flex flex-col gap-2">
                        <h2 class="text-[21px] leading-tight font-extrabold tracking-[-0.4px] text-white">{{ copy.title }}</h2>
                        <p class="mx-auto max-w-[300px] text-[13.5px] leading-[1.5] font-medium text-white/55">{{ copy.description }}</p>
                    </div>

                    <div class="h-[3px] w-[132px] overflow-hidden rounded-full bg-white/10">
                        <span class="lb-bar block h-full rounded-full bg-[#01B990]"></span>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.lb-logout-enter-active {
    transition: opacity 0.22s ease;
}

.lb-logout-leave-active {
    transition: opacity 0.35s ease;
}

.lb-logout-enter-from,
.lb-logout-leave-to {
    opacity: 0;
}

.lb-logout-enter-active .lb-logout-stack {
    animation: lb-logout-in 0.42s cubic-bezier(0.22, 1, 0.36, 1);
}

@keyframes lb-logout-in {
    from {
        opacity: 0;
        transform: translateY(14px) scale(0.96);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.lb-spin-arc {
    stroke-dasharray: 72 217;
    transform-origin: 52px 52px;
    animation: lb-arc 1.05s linear infinite;
}

@keyframes lb-arc {
    to {
        transform: rotate(360deg);
    }
}

.lb-pulse {
    animation: lb-pulse 1.9s ease-out infinite;
}

@keyframes lb-pulse {
    0% {
        transform: scale(1);
        opacity: 0.7;
    }
    70% {
        transform: scale(1.35);
        opacity: 0;
    }
    100% {
        opacity: 0;
    }
}

.lb-check {
    animation: lb-check-in 0.42s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes lb-check-in {
    from {
        transform: scale(0.4);
        opacity: 0;
    }
    to {
        transform: scale(1);
        opacity: 1;
    }
}

.lb-bar {
    width: 30%;
    animation: lb-bar-slide 1.15s ease-in-out infinite;
}

@keyframes lb-bar-slide {
    0% {
        transform: translateX(-110%);
    }
    100% {
        transform: translateX(360%);
    }
}
</style>
