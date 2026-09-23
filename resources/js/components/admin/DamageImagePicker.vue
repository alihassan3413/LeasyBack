<script setup lang="ts">
import DamageGalleryLightbox from '@/components/shared/DamageGalleryLightbox.vue';
import { cn } from '@/lib/utils';
import type { AdminReportDocument } from '@/types/admin';
import type { DamageGalleryImage } from '@/types/order';
import { computed, ref, type ComponentPublicInstance } from 'vue';
import MdiCheck from '~icons/mdi/check';
import MdiMagnifyPlusOutline from '~icons/mdi/magnify-plus-outline';

const props = withDefaults(
    defineProps<{
        modelValue: string[];
        documents: AdminReportDocument[];
        disabled?: boolean;
        label?: string;
    }>(),
    {
        disabled: false,
        label: 'Schadenbilder',
    },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: string[]): void }>();

const isPreviewOpen = ref(false);
const previewIndex = ref(0);
const previewButtons = ref<(HTMLButtonElement | null)[]>([]);

const imageDocuments = computed(() => props.documents.filter((document) => document.is_image));
const imageDocumentIds = computed(() => new Set(imageDocuments.value.map((document) => document.id)));
const selectedIds = computed(() => new Set(props.modelValue));
const selectedImageCount = computed(() => props.modelValue.filter((id) => imageDocumentIds.value.has(id)).length);
const linkedNonImageIds = computed(() => props.modelValue.filter((id) => !imageDocumentIds.value.has(id)));

const nonImageNotice = computed(() =>
    linkedNonImageIds.value.length === 1
        ? '1 verknüpftes Dokument ist kein Bild und wird Werkstätten nicht angezeigt.'
        : `${linkedNonImageIds.value.length} verknüpfte Dokumente sind keine Bilder und werden Werkstätten nicht angezeigt.`,
);

const galleryImages = computed<DamageGalleryImage[]>(() =>
    imageDocuments.value.map((document) => ({
        id: document.id,
        url: route('admin.vehicles.reports.image', document.id),
        caption: document.document_title,
    })),
);

function isSelected(documentId: string): boolean {
    return selectedIds.value.has(documentId);
}

function toggle(documentId: string) {
    if (props.disabled) {
        return;
    }

    emit('update:modelValue', isSelected(documentId) ? props.modelValue.filter((id) => id !== documentId) : [...props.modelValue, documentId]);
}

function removeNonImageLinks() {
    if (props.disabled) {
        return;
    }

    emit(
        'update:modelValue',
        props.modelValue.filter((id) => imageDocumentIds.value.has(id)),
    );
}

function imageName(document: AdminReportDocument, index: number): string {
    return document.document_title ? `Bild ${index + 1}: ${document.document_title}` : `Bild ${index + 1}`;
}

function setPreviewButton(index: number, element: Element | ComponentPublicInstance | null) {
    previewButtons.value[index] = element instanceof HTMLButtonElement ? element : null;
}

function openPreview(index: number) {
    previewIndex.value = index;
    isPreviewOpen.value = true;
}

function restorePreviewFocus() {
    previewButtons.value[previewIndex.value]?.focus();
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-2" data-testid="damage-image-picker">
        <p class="text-[12px] text-[#6f8585]" aria-live="polite">{{ label }}: {{ selectedImageCount }} von {{ imageDocuments.length }} ausgewählt</p>

        <p
            v-if="!imageDocuments.length"
            class="rounded-[13px] border border-dashed border-[#e9efee] px-3 py-4 text-center text-[12.5px] text-[#9bb0af]"
        >
            Für diesen Auftrag sind noch keine Bilder hochgeladen.
        </p>

        <ul
            v-else
            :aria-label="label"
            class="grid grid-cols-[repeat(auto-fill,minmax(112px,1fr))] gap-2 max-[560px]:grid-cols-[repeat(auto-fill,minmax(96px,1fr))]"
        >
            <li v-for="(document, index) in imageDocuments" :key="document.id" class="relative min-w-0">
                <button
                    type="button"
                    :aria-pressed="isSelected(document.id)"
                    :aria-label="imageName(document, index)"
                    :disabled="disabled"
                    :class="
                        cn(
                            'block aspect-[4/3] w-full cursor-pointer overflow-hidden rounded-[13px] border-2 bg-[#f6f9f8] transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] disabled:cursor-default motion-reduce:transition-none',
                            isSelected(document.id) ? 'border-[#01b990]' : 'border-[#e9efee] hover:border-[#10393b] disabled:hover:border-[#e9efee]',
                        )
                    "
                    @click="toggle(document.id)"
                >
                    <img
                        :src="route('admin.vehicles.reports.image', document.id)"
                        alt=""
                        loading="lazy"
                        decoding="async"
                        draggable="false"
                        :class="cn('size-full object-cover', disabled && !isSelected(document.id) && 'opacity-60')"
                    />
                    <span
                        aria-hidden="true"
                        :class="
                            cn(
                                'absolute top-2 left-2 flex size-6 items-center justify-center rounded-full border-2 transition-colors motion-reduce:transition-none',
                                isSelected(document.id)
                                    ? 'border-[#01b990] bg-[#01b990] text-white'
                                    : 'border-white bg-[#10393b]/40 text-transparent',
                            )
                        "
                        data-testid="damage-image-check"
                    >
                        <MdiCheck class="size-4" />
                    </span>
                </button>

                <button
                    :ref="(element) => setPreviewButton(index, element)"
                    type="button"
                    :aria-label="`${imageName(document, index)} vergrößern`"
                    class="group absolute right-0 bottom-0 flex h-11 w-11 cursor-pointer items-center justify-center rounded-[13px] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[#01b990]"
                    @click="openPreview(index)"
                >
                    <span
                        class="flex size-8 items-center justify-center rounded-full bg-white/90 text-[#10393b] shadow-sm transition-colors group-hover:bg-white motion-reduce:transition-none"
                    >
                        <MdiMagnifyPlusOutline class="size-[18px]" aria-hidden="true" />
                    </span>
                </button>
            </li>
        </ul>

        <div
            v-if="linkedNonImageIds.length"
            class="flex flex-wrap items-center justify-between gap-2 rounded-[13px] border border-[#e9efee] bg-[#f6f9f8] px-3 py-2"
        >
            <p class="text-[12px] text-[#6f8585]">{{ nonImageNotice }}</p>
            <button
                v-if="!disabled"
                type="button"
                class="min-h-11 cursor-pointer rounded-[11px] border border-[#e9efee] bg-white px-3.5 text-[12.5px] font-bold text-[#10393b] transition-all hover:border-[#10393b] hover:bg-[#f4f7f6] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                @click="removeNonImageLinks"
            >
                Verknüpfung entfernen
            </button>
        </div>

        <DamageGalleryLightbox
            v-model:open="isPreviewOpen"
            v-model:index="previewIndex"
            :images="galleryImages"
            :label="label"
            @closed="restorePreviewFocus"
        />
    </div>
</template>
