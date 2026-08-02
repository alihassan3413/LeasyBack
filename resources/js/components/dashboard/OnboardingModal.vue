<script setup lang="ts">
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        open: boolean;
        videoUrl?: string;
        posterUrl?: string;
    }>(),
    {
        videoUrl: '',
        posterUrl: '',
    },
);

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const isFileVideo = computed(() => /\.(mp4|webm|ogg)(\?.*)?$/i.test(props.videoUrl));

const highlights = [
    { key: 'vehicles', label: 'Fahrzeuge' },
    { key: 'returns', label: 'Rückgaben' },
    { key: 'orders', label: 'Aufträge' },
];

function close(): void {
    emit('update:open', false);
}
</script>

<template>
    <DialogRoot :open="open" @update:open="emit('update:open', $event)">
        <DialogPortal>
            <DialogOverlay
                class="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:fade-in-0 data-[state=closed]:fade-out-0 fixed inset-0 z-50 bg-black/40 backdrop-blur-[3px]"
            />
            <DialogContent
                class="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:fade-in-0 data-[state=closed]:fade-out-0 data-[state=open]:zoom-in-95 data-[state=closed]:zoom-out-95 data-[state=open]:slide-in-from-bottom-2 fixed top-1/2 left-1/2 z-50 max-h-[calc(100dvh_-_2rem)] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-x-hidden overflow-y-auto overscroll-contain rounded-[28px] bg-white shadow-[0_24px_70px_-12px_rgba(16,57,59,0.45)] duration-200 focus:outline-none"
            >
                <div
                    class="relative px-5 pt-7 pb-5 text-center sm:px-9 sm:pt-8 sm:pb-6"
                    style="background: linear-gradient(160deg, #10393b 0%, #16514f 55%, #1c6360 100%)"
                >
                    <div class="pointer-events-none absolute -top-12 -right-10 h-40 w-40 rounded-full opacity-30 blur-2xl" style="background: #01b990" />
                    <div class="pointer-events-none absolute top-6 -left-12 h-32 w-32 rounded-full opacity-20 blur-2xl" style="background: #ef8450" />

                    <DialogClose
                        aria-label="Schließen"
                        class="absolute top-4 right-4 z-10 flex h-9 w-9 items-center justify-center rounded-full text-white/80 backdrop-blur-sm transition-colors hover:bg-white/15 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white/50"
                    >
                        <IconMdiClose class="size-5" />
                        <span class="sr-only">Schließen</span>
                    </DialogClose>

                    <span
                        class="relative mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl shadow-lg ring-1 ring-white/25"
                        style="background: rgba(255, 255, 255, 0.14)"
                    >
                        <IconMdiHandWaveOutline class="size-7" style="color: #ffd9a8" />
                    </span>
                    <DialogTitle class="relative text-[22px] leading-snug font-bold text-white"> Willkommen bei LeasyBack </DialogTitle>
                    <DialogDescription class="relative mx-auto mt-2 max-w-md text-[13.5px] leading-relaxed text-white/80">
                        Schön, dass Sie da sind! LeasyBack unterstützt Sie dabei, Ihre Fahrzeuge, Rückgaben und Aufträge einfach und übersichtlich zu
                        verwalten.
                    </DialogDescription>

                    <div class="relative mt-5 flex items-center justify-center gap-2.5">
                        <div
                            v-for="item in highlights"
                            :key="item.key"
                            class="flex flex-1 flex-col items-center gap-1.5 rounded-2xl px-2 py-3"
                            style="background: rgba(255, 255, 255, 0.08)"
                        >
                            <IconMdiCarOutline v-if="item.key === 'vehicles'" class="size-5 text-white" />
                            <IconMdiSwapHorizontal v-else-if="item.key === 'returns'" class="size-5 text-white" />
                            <IconMdiClipboardTextOutline v-else class="size-5 text-white" />
                            <span class="text-[11.5px] font-medium text-white/85">{{ item.label }}</span>
                        </div>
                    </div>
                </div>

                <div class="px-5 pt-4 pb-6 sm:px-9 sm:pt-5 sm:pb-7">
                    <p class="text-[13px] leading-relaxed text-[#4a5c5a]">
                        In unserem Einführungsvideo erfahren Sie, wie Sie ein Fahrzeug anlegen, wichtige Daten hinterlegen und die nächsten Schritte
                        Ihrer Fahrzeugrückgabe planen.
                    </p>

                    <div class="group relative mt-4 aspect-video w-full overflow-hidden rounded-2xl ring-1 ring-black/5">
                        <video
                            v-if="videoUrl && isFileVideo"
                            :src="videoUrl"
                            :poster="posterUrl || undefined"
                            class="h-full w-full bg-black object-contain"
                            controls
                            controlslist="nodownload"
                            preload="metadata"
                            playsinline
                        >
                            Ihr Browser unterstützt die Videowiedergabe nicht.
                        </video>
                        <iframe
                            v-else-if="videoUrl"
                            :src="videoUrl"
                            title="LeasyBack Einführungsvideo"
                            class="h-full w-full"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                            allowfullscreen
                        />
                        <div
                            v-else
                            class="flex h-full w-full flex-col items-center justify-center gap-2.5 px-6 text-center"
                            style="background: radial-gradient(120% 120% at 50% 0%, #1c6360 0%, #10393b 70%)"
                        >
                            <span class="relative flex h-16 w-16 items-center justify-center">
                                <span class="absolute inset-0 rounded-full opacity-60" style="background: rgba(1, 185, 144, 0.25)" />
                                <span class="relative flex h-14 w-14 items-center justify-center rounded-full shadow-lg" style="background: #01b990">
                                    <IconMdiPlay class="size-7 text-white" />
                                </span>
                            </span>
                            <p class="mt-1 text-[14.5px] font-semibold text-white">Einführungsvideo folgt in Kürze</p>
                            <p class="max-w-xs text-[12px] leading-relaxed text-white/65">
                                Hier finden Sie bald eine kurze Einführung in die wichtigsten Funktionen von LeasyBack.
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="mt-6 inline-flex h-11 w-full items-center justify-center gap-2 rounded-full text-[15px] font-semibold text-white shadow-lg transition-all duration-200 hover:opacity-95 hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ef8450]/40"
                        style="background: #ef8450"
                        @click="close"
                    >
                        LeasyBack entdecken
                        <IconMdiArrowRight class="size-4" />
                    </button>
                    <p class="mt-3 text-center text-[12px] text-[#6a7c7a]">Sie können direkt mit der Verwaltung Ihrer Fahrzeuge beginnen.</p>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
