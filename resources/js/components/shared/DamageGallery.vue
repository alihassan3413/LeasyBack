<script setup lang="ts">
import DamageGalleryLightbox from '@/components/shared/DamageGalleryLightbox.vue';
import { cn } from '@/lib/utils';
import type { DamageGalleryImage } from '@/types/order';
import { computed, reactive, ref, watch, type ComponentPublicInstance, type HTMLAttributes } from 'vue';
import MdiImageBrokenVariant from '~icons/mdi/image-broken-variant';

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

type LoadState = 'loading' | 'loaded' | 'error';

const isOpen = ref(false);
const activeIndex = ref(0);
const thumbnails = ref<(HTMLButtonElement | null)[]>([]);
const states = reactive<Record<string, LoadState>>({});

const imageCount = computed(() => props.images.length);

watch(
    () => props.images.map((image) => image.id).join(','),
    () => {
        for (const image of props.images) {
            if (!states[image.id]) {
                states[image.id] = 'loading';
            }
        }
    },
    { immediate: true },
);

function stateFor(image: DamageGalleryImage): LoadState {
    return states[image.id] ?? 'loading';
}

/**
 * The grid asks for the derived thumbnail and the lightbox for the original.
 * A payload without `thumbnail_url` — an older response, or a caller that only
 * has one size — keeps working on the full image.
 */
function thumbnailSource(image: DamageGalleryImage): string {
    return image.thumbnail_url || image.url;
}

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
                    class="relative block aspect-[4/3] w-full cursor-pointer overflow-hidden rounded-[13px] border border-[#e9efee] bg-[#f6f9f8] transition-colors hover:border-[#10393b] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                    data-testid="damage-gallery-thumb"
                    @click="openAt(index)"
                >
                    <div
                        v-if="stateFor(image) === 'loading'"
                        class="absolute inset-0 animate-pulse bg-[#e9efee] motion-reduce:animate-none"
                        data-testid="damage-gallery-skeleton"
                        aria-hidden="true"
                    />

                    <div
                        v-else-if="stateFor(image) === 'error'"
                        class="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-[#f6f9f8] text-[#6f8585]"
                        data-testid="damage-gallery-fallback"
                        :title="`${label}: Bild nicht verfügbar`"
                    >
                        <MdiImageBrokenVariant class="size-5" aria-hidden="true" />
                        <span class="sr-only">Bild nicht verfügbar</span>
                    </div>

                    <!--
                        Opacity, not v-show: a lazily loaded image that is
                        display:none is never fetched by the browser, so
                        hiding it outright would leave the skeleton up forever.
                    -->
                    <img
                        :src="thumbnailSource(image)"
                        alt=""
                        loading="lazy"
                        decoding="async"
                        draggable="false"
                        :class="
                            cn(
                                'size-full object-cover transition-opacity duration-200 motion-reduce:transition-none',
                                stateFor(image) === 'loaded' ? 'opacity-100' : 'opacity-0',
                            )
                        "
                        @load="states[image.id] = 'loaded'"
                        @error="states[image.id] = 'error'"
                    />
                </button>
            </li>
        </ul>

        <DamageGalleryLightbox v-model:open="isOpen" v-model:index="activeIndex" :images="images" :label="label" @closed="restoreFocus" />
    </div>
</template>
