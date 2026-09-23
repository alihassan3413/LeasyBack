<script setup lang="ts">
import DamageGalleryLightbox from '@/components/shared/DamageGalleryLightbox.vue';
import { cn } from '@/lib/utils';
import type { DamageGalleryImage } from '@/types/order';
import { computed, ref, type ComponentPublicInstance, type HTMLAttributes } from 'vue';

const props = withDefaults(
    defineProps<{
        images: DamageGalleryImage[];
        label?: string;
        class?: HTMLAttributes['class'];
    }>(),
    {
        label: 'Schadenbilder',
        class: undefined,
    },
);

const isOpen = ref(false);
const activeIndex = ref(0);
const thumbnails = ref<(HTMLButtonElement | null)[]>([]);

const imageCount = computed(() => props.images.length);

function setThumbnail(index: number, element: Element | ComponentPublicInstance | null) {
    thumbnails.value[index] = element instanceof HTMLButtonElement ? element : null;
}

function thumbnailLabel(image: DamageGalleryImage, index: number): string {
    const position = `Bild ${index + 1} von ${imageCount.value}`;

    return image.caption ? `${props.label}, ${position}: ${image.caption} vergrößern` : `${props.label}, ${position} vergrößern`;
}

function openAt(index: number) {
    activeIndex.value = index;
    isOpen.value = true;
}

function restoreFocus() {
    thumbnails.value[activeIndex.value]?.focus();
}
</script>

<template>
    <div v-if="imageCount" :class="cn('min-w-0', props.class)" data-testid="damage-gallery">
        <ul
            :aria-label="label"
            class="grid grid-cols-[repeat(auto-fill,minmax(88px,1fr))] gap-2 max-[560px]:grid-cols-[repeat(auto-fill,minmax(72px,1fr))]"
        >
            <li v-for="(image, index) in images" :key="image.id" class="min-w-0">
                <button
                    :ref="(element) => setThumbnail(index, element)"
                    type="button"
                    :aria-label="thumbnailLabel(image, index)"
                    class="block aspect-[4/3] w-full cursor-pointer overflow-hidden rounded-[13px] border border-[#e9efee] bg-[#f6f9f8] transition-colors hover:border-[#10393b] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                    @click="openAt(index)"
                >
                    <img :src="image.url" alt="" loading="lazy" decoding="async" draggable="false" class="size-full object-cover" />
                </button>
            </li>
        </ul>

        <DamageGalleryLightbox v-model:open="isOpen" v-model:index="activeIndex" :images="images" :label="label" @closed="restoreFocus" />
    </div>
</template>
