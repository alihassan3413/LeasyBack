<script setup lang="ts">
/**
 * Unfallschaden files on the Admin order page (Accident Damage brief):
 * the customer's supporting files from the report form, and the final
 * accident damage documentation operations add. The customer sees the final
 * documentation once the order is completed.
 */
import InputError from '@/components/InputError.vue';
import { formatPortalDate } from '@/lib/portalDate';
import type { OrderAttachmentData } from '@/types/order';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MdiFileDocumentOutline from '~icons/mdi/file-document-outline';
import MdiTrayArrowUp from '~icons/mdi/tray-arrow-up';

const props = defineProps<{
    orderId: string;
    attachments: OrderAttachmentData[];
    /** False for a cancelled or discarded order. */
    editable: boolean;
}>();

const MAX_FILE_BYTES = 20 * 1024 * 1024;

const customerFiles = computed(() => props.attachments.filter((file) => file.kind === 'customer_upload'));
const finalDocuments = computed(() => props.attachments.filter((file) => file.kind === 'final_document'));

const form = useForm<{ files: File[] }>({ files: [] });
const fileInput = ref<HTMLInputElement | null>(null);
const rejected = ref<string[]>([]);

function addFiles(list: FileList | null) {
    if (!list) {
        return;
    }

    rejected.value = [];

    for (const file of Array.from(list)) {
        if (file.size > MAX_FILE_BYTES) {
            rejected.value.push(`${file.name} ist größer als 20 MB.`);
            continue;
        }

        form.files.push(file);
    }
}

function onFileInput(event: Event) {
    const input = event.target as HTMLInputElement;
    addFiles(input.files);
    input.value = '';
}

function upload() {
    if (!form.files.length || form.processing) {
        return;
    }

    form.post(route('admin.orders.accident-documents.store', props.orderId), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function remove(file: OrderAttachmentData) {
    if (!window.confirm(`„${file.original_name}" entfernen?`)) {
        return;
    }

    router.delete(route('admin.orders.accident-documents.destroy', file.id), { preserveScroll: true });
}

const fileErrors = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => key === 'files' || key.startsWith('files.'))
        .map(([, message]) => message),
);
</script>

<template>
    <div class="content-card">
        <div class="mb-4">
            <h2 class="text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Dateien &amp; Abschlussdokumentation</h2>
            <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">Der Kunde sieht die Abschlussdokumentation, sobald der Auftrag abgeschlossen ist.</p>
        </div>

        <h3 class="text-[13px] font-bold text-[#10393b]">Abschlussdokumentation</h3>
        <p v-if="!finalDocuments.length" class="py-2 text-[12.5px] text-[#9bb0af]">Noch keine Abschlussdokumente.</p>
        <ul v-else class="mb-2">
            <li v-for="file in finalDocuments" :key="file.id" class="flex items-center gap-3 rounded-[13px] px-2 py-2 hover:bg-[#f6f9f8]">
                <MdiFileDocumentOutline class="size-[17px] shrink-0 text-[#00856a]" />
                <span class="min-w-0 flex-1 truncate text-[13px] font-bold text-[#10393b]">{{ file.original_name }}</span>
                <span class="shrink-0 text-[11.5px] text-[#9bb0af]">{{ formatPortalDate(file.created_at) }}</span>
                <a v-if="file.url" :href="file.url" target="_blank" rel="noopener" class="shrink-0 text-[12px] font-bold text-[#00856a] hover:underline">
                    Öffnen
                </a>
                <button v-if="editable" type="button" class="shrink-0 text-[12px] font-bold text-[#c0392b] hover:underline" @click="remove(file)">
                    Entfernen
                </button>
            </li>
        </ul>

        <div v-if="editable" class="mt-2 flex flex-col gap-2">
            <button
                type="button"
                class="flex items-center justify-center gap-2 rounded-[13px] border-2 border-dashed border-[#e9efee] px-4 py-4 text-[12.5px] font-semibold text-[#5a6e6c] hover:border-[#01b990]"
                @click="fileInput?.click()"
            >
                <MdiTrayArrowUp class="size-5" />
                Abschlussdokumente auswählen (max. 20 MB pro Datei)
            </button>
            <input
                ref="fileInput"
                type="file"
                multiple
                accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.doc,.docx"
                class="hidden"
                @change="onFileInput"
            />

            <ul v-if="form.files.length" class="text-[12.5px] text-[#10393b]">
                <li v-for="(file, index) in form.files" :key="`${file.name}-${index}`" class="flex justify-between gap-2 py-0.5">
                    <span class="truncate">{{ file.name }}</span>
                    <button type="button" class="text-[#c0392b]" @click="form.files.splice(index, 1)">✕</button>
                </li>
            </ul>

            <p v-for="message in rejected" :key="message" class="text-[12px] text-red-600">{{ message }}</p>
            <InputError v-for="message in fileErrors" :key="message" :message="message" />

            <button
                type="button"
                :disabled="!form.files.length || form.processing"
                class="self-end rounded-[13px] bg-[#10393b] px-4 py-2.5 text-[13px] font-bold text-white hover:opacity-90 disabled:opacity-50"
                @click="upload"
            >
                {{ form.processing ? 'Wird hochgeladen …' : 'Hochladen' }}
            </button>
        </div>

        <h3 class="mt-5 text-[13px] font-bold text-[#10393b]">Vom Kunden hochgeladen</h3>
        <p v-if="!customerFiles.length" class="py-2 text-[12.5px] text-[#9bb0af]">Der Kunde hat keine Dateien hochgeladen.</p>
        <ul v-else>
            <li v-for="file in customerFiles" :key="file.id" class="flex items-center gap-3 rounded-[13px] px-2 py-2 hover:bg-[#f6f9f8]">
                <MdiFileDocumentOutline class="size-[17px] shrink-0 text-[#6f8585]" />
                <span class="min-w-0 flex-1 truncate text-[13px] text-[#10393b]">{{ file.original_name }}</span>
                <span class="shrink-0 text-[11.5px] text-[#9bb0af]">{{ formatPortalDate(file.created_at) }}</span>
                <a v-if="file.url" :href="file.url" target="_blank" rel="noopener" class="shrink-0 text-[12px] font-bold text-[#00856a] hover:underline">
                    Öffnen
                </a>
            </li>
        </ul>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>