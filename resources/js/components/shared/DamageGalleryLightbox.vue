<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { DamageGalleryImage } from '@/types/order';
import { onKeyStroke, usePointerSwipe } from '@vueuse/core';
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import MdiChevronLeft from '~icons/mdi/chevron-left';
import MdiChevronRight from '~icons/mdi/chevron-right';
import MdiClose from '~icons/mdi/close';
import MdiImageBrokenVariant from '~icons/mdi/image-broken-variant';
import MdiMagnifyMinusOutline from '~icons/mdi/magnify-minus-outline';
import MdiMagnifyPlusOutline from '~icons/mdi/magnify-plus-outline';
import MdiRestore from '~icons/mdi/restore';

const props = withDefaults(
    defineProps<{
        open: boolean;
        images: DamageGalleryImage[];
        index: number;
        label?: string;
    }>(),
    {
        label: 'Schadenbilder',
    },
);

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'update:index', value: number): void;
    (e: 'closed'): void;
}>();

const MIN_ZOOM = 1;
const MAX_ZOOM = 4;
const ZOOM_STEP = 0.5;

const stage = ref<HTMLElement | null>(null);
const zoom = ref(MIN_ZOOM);
const offset = ref({ x: 0, y: 0 });
const panOrigin = ref<{ x: number; y: number } | null>(null);
const failed = ref(false);

const imageCount = computed(() => props.images.length);
const currentIndex = computed(() => Math.min(Math.max(props.index, 0), Math.max(imageCount.value - 1, 0)));
const currentImage = computed(() => props.images[currentIndex.value] ?? null);
const hasPrevious = computed(() => currentIndex.value > 0);
const hasNext = computed(() => currentIndex.value < imageCount.value - 1);
const positionText = computed(() => `Bild ${currentIndex.value + 1} von ${imageCount.value}`);
const imageAlt = computed(() => currentImage.value?.caption || `${props.label}, ${positionText.value}`);

const isZoomed = computed(() => zoom.value > MIN_ZOOM);
const canZoomIn = computed(() => zoom.value < MAX_ZOOM);
const canZoomOut = computed(() => zoom.value > MIN_ZOOM);
const zoomPercent = computed(() => `${Math.round(zoom.value * 100)}%`);
const imageTransform = computed(() => `translate(${offset.value.x}px, ${offset.value.y}px) scale(${zoom.value})`);

const controlClass =
    'flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:cursor-default disabled:opacity-30 disabled:hover:bg-white/10 motion-reduce:transition-none';

function resetZoom() {
    zoom.value = MIN_ZOOM;
    offset.value = { x: 0, y: 0 };
}

function setZoom(value: number) {
    zoom.value = Math.min(Math.max(Number(value.toFixed(2)), MIN_ZOOM), MAX_ZOOM);

    if (!isZoomed.value) {
        offset.value = { x: 0, y: 0 };
    }
}

function zoomIn() {
    setZoom(zoom.value + ZOOM_STEP);
}

function zoomOut() {
    setZoom(zoom.value - ZOOM_STEP);
}

function goTo(index: number) {
    if (index >= 0 && index < imageCount.value && index !== currentIndex.value) {
        emit('update:index', index);
    }
}

function showPrevious() {
    goTo(currentIndex.value - 1);
}

function showNext() {
    goTo(currentIndex.value + 1);
}

// A new image, or a reopened dialog, always starts unzoomed and unpanned —
// otherwise the next photo opens scrolled to wherever the last one was left.
watch([currentIndex, () => props.open], () => {
    resetZoom();
    failed.value = false;
});

function handleKey(event: KeyboardEvent, action: () => void) {
    if (!props.open) {
        return;
    }

    event.preventDefault();
    action();
}

onKeyStroke('ArrowLeft', (event) => handleKey(event, showPrevious));
onKeyStroke('ArrowRight', (event) => handleKey(event, showNext));
onKeyStroke('Home', (event) => handleKey(event, () => goTo(0)));
onKeyStroke('End', (event) => handleKey(event, () => goTo(imageCount.value - 1)));
onKeyStroke(['+', '='], (event) => handleKey(event, zoomIn));
onKeyStroke('-', (event) => handleKey(event, zoomOut));
onKeyStroke('0', (event) => handleKey(event, resetZoom));

usePointerSwipe(stage, {
    threshold: 40,
    pointerTypes: ['touch', 'pen', 'mouse'],
    onSwipeEnd(_event, direction) {
        // While zoomed a drag pans the photo, so it must not also page to the
        // next one.
        if (isZoomed.value) {
            return;
        }

        if (direction === 'left') {
            showNext();
        } else if (direction === 'right') {
            showPrevious();
        }
    },
});

function startPan(event: PointerEvent) {
    if (!isZoomed.value) {
        return;
    }

    panOrigin.value = { x: event.clientX - offset.value.x, y: event.clientY - offset.value.y };
}

function movePan(event: PointerEvent) {
    if (panOrigin.value === null) {
        return;
    }

    offset.value = { x: event.clientX - panOrigin.value.x, y: event.clientY - panOrigin.value.y };
}

function endPan() {
    panOrigin.value = null;
}

function handleCloseAutoFocus(event: Event) {
    event.preventDefault();
    emit('closed');
}
</script>

<template>
    <DialogRoot :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-50 bg-[#10393b]/90" />
            <DialogContent
                class="fixed inset-0 z-50 flex flex-col gap-3 overflow-hidden p-4 outline-none md:p-8"
                data-testid="damage-gallery-lightbox"
                @close-auto-focus="handleCloseAutoFocus"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <DialogTitle class="truncate text-[15px] font-extrabold text-white">{{ label }}</DialogTitle>
                        <DialogDescription class="text-[12.5px] font-bold text-white/70 tabular-nums" aria-live="polite">
                            {{ positionText }}
                        </DialogDescription>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <button
                            type="button"
                            :class="controlClass"
                            :disabled="!canZoomOut"
                            aria-label="Verkleinern"
                            data-testid="damage-gallery-zoom-out"
                            @click="zoomOut"
                        >
                            <MdiMagnifyMinusOutline class="size-6" aria-hidden="true" />
                        </button>
                        <span
                            class="min-w-[4ch] text-center text-[12.5px] font-bold text-white/70 tabular-nums"
                            aria-live="polite"
                            data-testid="damage-gallery-zoom-level"
                        >
                            {{ zoomPercent }}
                        </span>
                        <button
                            type="button"
                            :class="controlClass"
                            :disabled="!canZoomIn"
                            aria-label="Vergrößern"
                            data-testid="damage-gallery-zoom-in"
                            @click="zoomIn"
                        >
                            <MdiMagnifyPlusOutline class="size-6" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            :class="controlClass"
                            :disabled="!isZoomed"
                            aria-label="Zoom zurücksetzen"
                            data-testid="damage-gallery-zoom-reset"
                            @click="resetZoom"
                        >
                            <MdiRestore class="size-6" aria-hidden="true" />
                        </button>
                        <DialogClose :class="controlClass" aria-label="Schließen" data-testid="damage-gallery-close">
                            <MdiClose class="size-6" aria-hidden="true" />
                        </DialogClose>
                    </div>
                </div>

                <div
                    ref="stage"
                    class="relative flex min-h-0 flex-1 touch-pan-y items-center justify-center overflow-hidden select-none"
                    data-testid="damage-gallery-stage"
                >
                    <div v-if="failed" class="flex flex-col items-center gap-2 text-white/70" data-testid="damage-gallery-lightbox-fallback">
                        <MdiImageBrokenVariant class="size-10" aria-hidden="true" />
                        <p class="text-[12.5px] font-bold">Bild nicht verfügbar</p>
                    </div>

                    <img
                        v-else-if="currentImage"
                        :key="currentImage.id"
                        :src="currentImage.url"
                        :alt="imageAlt"
                        decoding="async"
                        draggable="false"
                        data-testid="damage-gallery-image"
                        class="max-h-full max-w-full rounded-[13px] object-contain transition-transform duration-150 motion-reduce:transition-none"
                        :style="{ transform: imageTransform, cursor: isZoomed ? (panOrigin ? 'grabbing' : 'grab') : 'auto' }"
                        @error="failed = true"
                        @pointerdown="startPan"
                        @pointermove="movePan"
                        @pointerup="endPan"
                        @pointercancel="endPan"
                        @pointerleave="endPan"
                        @dblclick="isZoomed ? resetZoom() : zoomIn()"
                    />

                    <template v-if="imageCount > 1">
                        <button
                            type="button"
                            :class="cn(controlClass, 'absolute top-1/2 left-0 -translate-y-1/2 md:left-2')"
                            :disabled="!hasPrevious"
                            aria-label="Vorheriges Bild"
                            data-testid="damage-gallery-previous"
                            @click="showPrevious"
                        >
                            <MdiChevronLeft class="size-7" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            :class="cn(controlClass, 'absolute top-1/2 right-0 -translate-y-1/2 md:right-2')"
                            :disabled="!hasNext"
                            aria-label="Nächstes Bild"
                            data-testid="damage-gallery-next"
                            @click="showNext"
                        >
                            <MdiChevronRight class="size-7" aria-hidden="true" />
                        </button>
                    </template>
                </div>

                <p v-if="currentImage?.caption" class="mx-auto max-w-[70ch] text-center text-[12.5px] text-white/80">
                    {{ currentImage.caption }}
                </p>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
