<script setup lang="ts">
import { ref } from 'vue';
import MdiFileDocumentOutline from '~icons/mdi/file-document-outline';
import MdiTrayArrowUp from '~icons/mdi/tray-arrow-up';

const file = defineModel<File | null>({ required: true });

defineProps<{ hint?: string }>();

const dragging = ref(false);
const input = ref<HTMLInputElement | null>(null);

function pick() {
    input.value?.click();
}

function onChange(event: Event) {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function onDrop(event: DragEvent) {
    dragging.value = false;
    file.value = event.dataTransfer?.files?.[0] ?? null;
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
            {{ file ? 'Andere Datei wählen' : (hint ?? 'PDF, JPG oder PNG · max. 50 MB') }}
        </p>
    </div>
</template>
