<script setup lang="ts">
import { useSessionGuard } from '@/composables/useSessionGuard';
import { AlertDialogContent, AlertDialogDescription, AlertDialogOverlay, AlertDialogPortal, AlertDialogRoot, AlertDialogTitle } from 'reka-ui';
import { computed, ref } from 'vue';
import MdiTimerSandComplete from '~icons/mdi/timer-sand-complete';

/*
 * A Reka AlertDialog rather than a hand-rolled overlay: it joins Reka's layer
 * stack, so it sits above a booking modal that is already open, receives the
 * pointer and the focus trap from it, and hands focus back when it closes —
 * the modal underneath stays open, untouched. Rendered outside that stack, the
 * warning could not be clicked while any Reka modal was open (Reka disables
 * pointer events on everything outside its top layer).
 *
 * `open` follows warningVisible one way. The two buttons are ordinary buttons
 * with their own handlers, not AlertDialogAction/Cancel: both of those close
 * through the same update:open event, and "log out" must never read as a
 * dismissal.
 */
const { warningVisible, secondsRemaining, countdownProgress, staySignedIn, logout } = useSessionGuard();

const RADIUS = 34;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

const dashOffset = computed(() => CIRCUMFERENCE * (1 - countdownProgress.value));
const urgent = computed(() => secondsRemaining.value <= 10);

const stayButton = ref<HTMLButtonElement | null>(null);

/** Focus the safe choice: Reka would otherwise focus the dialog, and Enter must not log anyone out. */
function focusStay(event: Event) {
    event.preventDefault();
    stayButton.value?.focus();
}
</script>

<template>
    <AlertDialogRoot :open="warningVisible">
        <AlertDialogPortal>
            <AlertDialogOverlay class="lb-idle-overlay fixed inset-0 z-[190] bg-[#0d3133]/45 backdrop-blur-[3px]" />

            <!-- Escape is ignored: the warning wants an explicit choice, as before. -->
            <AlertDialogContent
                class="lb-idle-card fixed top-1/2 left-1/2 z-[191] w-[calc(100%-2rem)] max-w-[380px] -translate-x-1/2 -translate-y-1/2 overflow-hidden rounded-[22px] border border-white/10 px-7 pt-8 pb-7 text-center focus:outline-none"
                style="background: linear-gradient(180deg, #10393b 0%, #0d3133 100%); box-shadow: 0 24px 60px rgba(16, 57, 59, 0.45)"
                @open-auto-focus="focusStay"
                @escape-key-down.prevent
            >
                <div class="relative mx-auto mb-5 size-[84px]">
                    <svg class="size-full -rotate-90" viewBox="0 0 84 84" aria-hidden="true">
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
                            style="
                                transition:
                                    stroke-dashoffset 0.5s linear,
                                    stroke 0.3s ease;
                            "
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
                    <MdiTimerSandComplete class="text-[18px]" aria-hidden="true" />
                </div>

                <AlertDialogTitle class="text-[18px] font-extrabold tracking-[-0.2px] text-white">Sind Sie noch da?</AlertDialogTitle>
                <AlertDialogDescription class="mx-auto mt-2 max-w-[280px] text-[13px] leading-[1.5] text-white/55">
                    Aus Sicherheitsgründen melden wir Sie nach 5 Minuten Inaktivität automatisch ab.
                </AlertDialogDescription>

                <div class="mt-6 flex flex-col gap-2.5">
                    <button
                        ref="stayButton"
                        type="button"
                        class="h-11 w-full rounded-full bg-[#01B990] text-[14px] font-bold text-white shadow-[0_8px_20px_rgba(1,185,144,0.32)] transition-all hover:brightness-105 focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:outline-none active:scale-[0.98]"
                        @click="staySignedIn"
                    >
                        Angemeldet bleiben
                    </button>
                    <button
                        type="button"
                        class="h-11 w-full rounded-full border border-white/15 text-[13.5px] font-semibold text-white/60 transition-colors hover:border-white/30 hover:text-white focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:outline-none"
                        @click="logout('manual')"
                    >
                        Jetzt abmelden
                    </button>
                </div>
            </AlertDialogContent>
        </AlertDialogPortal>
    </AlertDialogRoot>
</template>

<style scoped>
.lb-idle-overlay[data-state='open'] {
    animation: lb-idle-fade-in 0.2s ease;
}

.lb-idle-overlay[data-state='closed'] {
    animation: lb-idle-fade-out 0.16s ease;
}

.lb-idle-card[data-state='open'] {
    animation: lb-idle-in 0.34s cubic-bezier(0.22, 1, 0.36, 1);
}

.lb-idle-card[data-state='closed'] {
    animation: lb-idle-fade-out 0.16s ease;
}

@keyframes lb-idle-fade-in {
    from {
        opacity: 0;
    }
}

@keyframes lb-idle-fade-out {
    to {
        opacity: 0;
    }
}

/* The card is centred with translate(-50%, -50%); the entrance keeps that offset. */
@keyframes lb-idle-in {
    from {
        opacity: 0;
        transform: translate(-50%, calc(-50% + 16px)) scale(0.94);
    }
    to {
        opacity: 1;
        transform: translate(-50%, -50%) scale(1);
    }
}
</style>
