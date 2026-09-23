<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { DamageGalleryImage } from '@/types/order';
import { onKeyStroke, usePointerSwipe } from '@vueuse/core';
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui';
import { computed, ref } from 'vue';
import MdiChevronLeft from '~icons/mdi/chevron-left';
import MdiChevronRight from '~icons/mdi/chevron-right';
import MdiClose from '~icons/mdi/close';

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

const stage = ref<HTMLElement | null>(null);

const imageCount = computed(() => props.images.length);
const currentIndex = computed(() => Math.min(Math.max(props.index, 0), Math.max(imageCount.value - 1, 0)));
const currentImage = computed(() => props.images[currentIndex.value] ?? null);
const hasPrevious = computed(() => currentIndex.value > 0);
const hasNext = computed(() => currentIndex.value < imageCount.value - 1);
const positionText = computed(() => `Bild ${currentIndex.value + 1} von ${imageCount.value}`);
const imageAlt = computed(() => currentImage.value?.caption || `${props.label}, ${positionText.value}`);

const controlClass =
    'flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:cursor-default disabled:opacity-30 disabled:hover:bg-white/10 motion-reduce:transition-none';

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

usePointerSwipe(stage, {
    threshold: 40,
    pointerTypes: ['touch', 'pen', 'mouse'],
    onSwipeEnd(_event, direction) {
        if (direction === 'left') {
            showNext();
        } else if (direction === 'right') {
            showPrevious();
        }
    },
});

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
                    <DialogClose :class="controlClass" aria-label="Schließen">
                        <MdiClose class="size-6" aria-hidden="true" />
                    </DialogClose>
                </div>

                <div
                    ref="stage"
                    class="relative flex min-h-0 flex-1 touch-pan-y items-center justify-center select-none"
                    data-testid="damage-gallery-stage"
                >
                    <img
                        v-if="currentImage"
                        :key="currentImage.id"
                        :src="currentImage.url"
                        :alt="imageAlt"
                        decoding="async"
                        draggable="false"
                        class="max-h-full max-w-full rounded-[13px] object-contain"
                    />

                    <template v-if="imageCount > 1">
                        <button
                            type="button"
                            :class="cn(controlClass, 'absolute top-1/2 left-0 -translate-y-1/2 md:left-2')"
                            :disabled="!hasPrevious"
                            aria-label="Vorheriges Bild"
                            @click="showPrevious"
                        >
                            <MdiChevronLeft class="size-7" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            :class="cn(controlClass, 'absolute top-1/2 right-0 -translate-y-1/2 md:right-2')"
                            :disabled="!hasNext"
                            aria-label="Nächstes Bild"
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
