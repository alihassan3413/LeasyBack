<script setup lang="ts">
import { computed, ref } from 'vue';
import MdiFileDocumentOutline from '~icons/mdi/file-document-outline';
import MdiTrayArrowUp from '~icons/mdi/tray-arrow-up';

const file = defineModel<File | null>({ required: true });

const props = withDefaults(defineProps<{ hint?: string; maxSizeMb?: number }>(), { maxSizeMb: 50 });

const dragging = ref(false);
const sizeError = ref('');
const input = ref<HTMLInputElement | null>(null);

const maxBytes = computed(() => props.maxSizeMb * 1024 * 1024);
const defaultHint = computed(() => `PDF, JPG oder PNG · max. ${props.maxSizeMb} MB`);

function pick() {
    input.value?.click();
}

function accept(candidate: File | null) {
    if (candidate !== null && candidate.size > maxBytes.value) {
        sizeError.value = `${candidate.name} ist ${(candidate.size / 1024 / 1024).toLocaleString('de-DE', { maximumFractionDigits: 1 })} MB groß. Erlaubt sind ${props.maxSizeMb} MB.`;
        file.value = null;

        return;
    }

    sizeError.value = '';
    file.value = candidate;
}

function onChange(event: Event) {
    accept((event.target as HTMLInputElement).files?.[0] ?? null);
}

function onDrop(event: DragEvent) {
    dragging.value = false;
    accept(event.dataTransfer?.files?.[0] ?? null);
}
</script>

<template>
    <div
        class="relative flex h-[130px] w-full cursor-pointer flex-col items-center justify-center rounded-2xl border border-dashed transition-colors"
        :class="dragging ? 'border-emerald-500 bg-emerald-50' : 'border-gray-300 bg-gray-50'"
        @click="pick"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop"
    >
        <input ref="input" type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="onChange" />

        <component :is="file ? MdiFileDocumentOutline : MdiTrayArrowUp" class="size-8 text-gray-400" />

        <p class="mt-2 px-4 text-center text-sm font-medium text-black">
            {{ file ? file.name : 'Zum Hochladen klicken oder Datei hierher ziehen' }}
        </p>
        <p class="text-xs font-light text-[#00000080]">
            {{ file ? 'Andere Datei wählen' : (hint ?? defaultHint) }}
        </p>
        <p v-if="sizeError" role="alert" class="mt-1 px-4 text-center text-xs font-bold text-[#c0392b]" data-testid="dropzone-size-error">
            {{ sizeError }}
        </p>
    </div>
</template>
