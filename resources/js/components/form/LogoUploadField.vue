<script setup lang="ts">
/**
 * Drag-and-drop logo picker with a live preview. Keeps the already-stored
 * logo visible until the user replaces or removes it, so "no new file
 * selected" never reads as "delete my logo" — the caller distinguishes the
 * two through the model (a new File) and the `remove` event.
 */
import { onBeforeUnmount, ref, watch } from 'vue';

const file = defineModel<File | null>({ required: true });

const props = withDefaults(
    defineProps<{
        /** URL of the logo already stored for this company, if any. */
        existingUrl?: string | null;
        accept?: string;
        /** Client-side ceiling, in megabytes. Mirrored by the server rule. */
        maxSizeMb?: number;
        disabled?: boolean;
    }>(),
    {
        existingUrl: null,
        accept: '.jpg,.jpeg,.png',
        maxSizeMb: 8,
        disabled: false,
    },
);

const emit = defineEmits<{ remove: [] }>();

const ALLOWED_TYPES = ['image/jpeg', 'image/png'];

const input = ref<HTMLInputElement | null>(null);
const dragging = ref(false);
const objectUrl = ref('');
const localError = ref('');

// Revoked on every replacement so a long editing session can't leak blobs.
function setObjectUrl(next: string) {
    if (objectUrl.value) {
        URL.revokeObjectURL(objectUrl.value);
    }

    objectUrl.value = next;
}

watch(file, (selected) => {
    setObjectUrl(selected ? URL.createObjectURL(selected) : '');
});

onBeforeUnmount(() => setObjectUrl(''));

function pick() {
    if (!props.disabled) {
        input.value?.click();
    }
}

function accepts(candidate: File): boolean {
    if (!ALLOWED_TYPES.includes(candidate.type)) {
        localError.value = 'Bitte laden Sie nur JPG- oder PNG-Dateien hoch.';

        return false;
    }

    if (candidate.size > props.maxSizeMb * 1024 * 1024) {
        localError.value = `Die Datei darf maximal ${props.maxSizeMb} MB groß sein.`;

        return false;
    }

    localError.value = '';

    return true;
}

function select(candidate: File | null) {
    if (!candidate) {
        return;
    }

    if (accepts(candidate)) {
        file.value = candidate;
    }

    // Clearing the native input lets the same file be re-picked after a
    // rejection, which the browser would otherwise treat as "no change".
    if (input.value) {
        input.value.value = '';
    }
}

function onChange(event: Event) {
    select((event.target as HTMLInputElement).files?.[0] ?? null);
}

function onDrop(event: DragEvent) {
    dragging.value = false;

    if (!props.disabled) {
        select(event.dataTransfer?.files?.[0] ?? null);
    }
}

function clear() {
    localError.value = '';
    file.value = null;

    if (input.value) {
        input.value.value = '';
    }

    emit('remove');
}
</script>

<template>
    <div class="space-y-1.5">
        <div
            class="relative flex min-h-[150px] w-full flex-col items-center justify-center rounded-[5px] border border-dashed px-6 py-8 text-center transition-colors"
            :class="[
                disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
                dragging ? 'border-brand-green bg-[#F0FBF8]' : 'border-brand-green-gray bg-white hover:border-brand-green',
            ]"
            role="button"
            tabindex="0"
            :aria-disabled="disabled || undefined"
            @click="pick"
            @keydown.enter.prevent="pick"
            @keydown.space.prevent="pick"
            @dragover.prevent="dragging = !disabled"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <input ref="input" type="file" class="hidden" :accept="accept" :disabled="disabled" @change="onChange" />

            <template v-if="objectUrl || existingUrl">
                <img :src="objectUrl || existingUrl || ''" alt="Logo Vorschau" class="mb-3 h-20 w-20 object-contain" />

                <span v-if="file" class="text-brand-teal max-w-full truncate text-sm font-bold">{{ file.name }}</span>

                <span class="text-brand-green mt-1 text-sm font-bold underline">Anderes Logo auswählen</span>
            </template>

            <template v-else>
                <IconMdiTrayArrowUp class="text-brand-green-gray mb-2 size-8" aria-hidden="true" />

                <p class="text-brand-teal text-sm font-bold">
                    Datei hierher ziehen oder zum Hochladen
                    <span class="text-brand-green underline">durchsuchen</span>
                </p>

                <p class="mt-1 text-xs tracking-[1px] text-[#00000080]">JPG oder PNG • {{ maxSizeMb }} MB max</p>
            </template>

            <button
                v-if="(file || existingUrl) && !disabled"
                type="button"
                class="text-brand-green-gray hover:bg-brand-green-gray/20 hover:text-brand-teal absolute top-2 right-2 flex size-8 items-center justify-center rounded-full transition-colors"
                aria-label="Logo entfernen"
                @click.stop="clear"
            >
                <IconMdiClose class="size-5" aria-hidden="true" />
            </button>
        </div>

        <p v-if="localError" class="text-destructive text-sm">{{ localError }}</p>
    </div>
</template>
