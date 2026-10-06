<script setup lang="ts">
/**
 * Gutachten: the final appraisal report. Uploading it on a scheduled order
 * completes the order; the customer sees the report from then on.
 */
import { formatPortalDate } from '@/lib/portalDate';
import type { OrderAttachmentData } from '@/types/order';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    orderId: string;
    reports: OrderAttachmentData[];
    orderStatus: string;
}>();

const fileInput = ref<HTMLInputElement | null>(null);
const upload = useForm({ files: [] as File[] });
const removal = useForm({});

/** The server accepts a report once the appointment is confirmed, and afterwards for a corrected one. */
const canUpload = computed(() => props.orderStatus === 'confirmed' || props.orderStatus === 'completed');
const isClosedEarly = computed(() => props.orderStatus === 'cancelled' || props.orderStatus === 'discarded');

const hint = computed(() => {
    if (isClosedEarly.value) {
        return 'Der Auftrag wurde storniert oder abgelehnt.';
    }

    if (props.orderStatus === 'completed') {
        return 'Der Auftrag ist abgeschlossen. Der Kunde sieht das Gutachten im Portal.';
    }

    return canUpload.value
        ? 'Mit dem Hochladen des Gutachtens wird der Auftrag abgeschlossen.'
        : 'Das Gutachten kann hinzugefügt werden, sobald der Termin bestätigt ist.';
});

const uploadError = computed(() => Object.values(upload.errors)[0] ?? '');
const removalError = computed(() => Object.values(removal.errors)[0] ?? '');

function pick(event: Event) {
    upload.files = Array.from((event.target as HTMLInputElement).files ?? []);
}

function submit() {
    upload.post(route('admin.orders.appraisal.report.store', props.orderId), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            upload.reset();

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

function remove(file: OrderAttachmentData) {
    removal.delete(route('admin.orders.appraisal.report.destroy', file.id), { preserveScroll: true });
}
</script>

<template>
    <div class="content-card">
        <div class="mb-4">
            <h2 class="text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Abschlussgutachten</h2>
            <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">{{ hint }}</p>
        </div>

        <p v-if="!reports.length" class="py-4 text-[13px] text-[#9bb0af]">Noch kein Gutachten hochgeladen.</p>

        <div v-else class="mb-3 flex flex-col gap-1">
            <div v-for="file in reports" :key="file.id" class="flex items-center gap-3 rounded-[13px] px-3 py-2.5 transition-colors hover:bg-[#f6f9f8]">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[13px] font-bold text-[#10393b]">{{ file.original_name }}</p>
                    <p class="truncate text-[11.5px] text-[#6f8585]">{{ formatPortalDate(file.created_at ?? null) }}</p>
                </div>

                <a
                    v-if="file.url"
                    :href="file.url"
                    target="_blank"
                    rel="noopener"
                    class="shrink-0 rounded-full border border-[#e9efee] px-3 py-1 text-[11.5px] font-bold text-[#10393b] transition-all hover:border-[#01B990] hover:text-[#00856a]"
                >
                    Öffnen
                </a>

                <button
                    v-if="!isClosedEarly"
                    type="button"
                    :disabled="removal.processing"
                    class="shrink-0 rounded-full border border-[#e9efee] px-3 py-1 text-[11.5px] font-bold text-[#b03b28] transition-all hover:border-[#b03b28] disabled:opacity-40"
                    @click="remove(file)"
                >
                    Entfernen
                </button>
            </div>
        </div>

        <p v-if="removalError" class="mb-3 text-[12px] font-medium text-[#b03b28]">{{ removalError }}</p>

        <form v-if="canUpload" class="flex flex-col gap-3" @submit.prevent="submit">
            <input
                ref="fileInput"
                type="file"
                multiple
                accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.doc,.docx"
                class="block w-full text-[12.5px] text-[#5a6e6c] file:mr-3 file:rounded-full file:border-0 file:bg-[#f4f7f6] file:px-4 file:py-2 file:text-[12px] file:font-bold file:text-[#10393b]"
                @change="pick"
            />
            <p v-if="uploadError" class="text-[12px] font-medium text-[#b03b28]">{{ uploadError }}</p>

            <button
                type="submit"
                :disabled="upload.processing || !upload.files.length"
                class="self-start rounded-[13px] bg-[#10393b] px-5 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
            >
                {{ upload.processing ? 'Wird hochgeladen…' : orderStatus === 'confirmed' ? 'Hochladen und Auftrag abschließen' : 'Gutachten hochladen' }}
            </button>
        </form>
    </div>
</template>
