<script setup lang="ts">
/**
 * Internal B2B billing state (b2b.txt §13, §21).
 *
 * There is no accounting or payment integration behind this — it records
 * whether Leasyback has processed the billing, which is the fact the
 * completion gate reads. Marking processed is one-way: the server does not
 * offer un-marking, because a completed order would then lose the
 * justification it was completed on.
 */
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import type { AdminOrderBilling, AdminReportDocument } from '@/types/admin';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MdiReceiptTextOutline from '~icons/mdi/receipt-text-outline';

const props = defineProps<{
    orderId: string;
    billing: AdminOrderBilling;
    reportDocuments: AdminReportDocument[];
}>();

const form = useForm(() => ({
    invoice_reference: props.billing.invoice_reference ?? '',
    invoice_document_id: props.billing.invoice_document_id ?? '',
    mark_processed: props.billing.is_processed,
}));

const isProcessed = computed(() => props.billing.is_processed);

function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' });
}

function documentLabel(document: AdminReportDocument): string {
    return document.document_title || document.document_type || 'Dokument';
}

function submit() {
    form.transform((data) => ({
        ...data,
        invoice_document_id: data.invoice_document_id === '' ? null : data.invoice_document_id,
    })).patch(route('admin.orders.billing', props.orderId), { preserveScroll: true });
}
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#4FA3A6]/15 text-[#2c7a7d]">
                <MdiReceiptTextOutline class="size-[17px]" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Abrechnung</h2>
                <p class="mt-0.5 text-[11.5px] font-medium text-[#9bb0af]">
                    {{ isProcessed ? `Verarbeitet am ${formatDateTime(billing.processed_at)}` : 'Noch nicht verarbeitet' }}
                </p>
            </div>
            <span
                class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-bold"
                :class="isProcessed ? 'bg-[#01B990]/10 text-[#00856a]' : 'bg-[#f4f7f6] text-[#6f8585]'"
            >
                {{ isProcessed ? 'Verarbeitet' : 'Offen' }}
            </span>
        </div>

        <p v-if="!isProcessed" class="mb-3 rounded-[11px] bg-[#f6f9f8] px-3 py-2 text-[11.5px] text-[#6f8585]">
            Der Auftrag kann erst abgeschlossen werden, wenn die Abrechnung als verarbeitet markiert ist.
        </p>

        <form class="flex flex-col gap-3" @submit.prevent="submit">
            <div class="flex flex-col gap-1">
                <label class="text-[12px] font-bold text-[#10393b]">Rechnungsnummer / Referenz</label>
                <Input v-model="form.invoice_reference" placeholder="optional" />
                <InputError :message="form.errors.invoice_reference" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-[12px] font-bold text-[#10393b]">Rechnungsdokument</label>
                <select
                    v-model="form.invoice_document_id"
                    class="w-full rounded-[13px] border border-[#e9efee] px-3 py-2 text-[12.5px] outline-none focus:border-[#01b990]"
                >
                    <option value="">Kein Dokument verknüpft</option>
                    <option v-for="document in reportDocuments" :key="document.id" :value="document.id">
                        {{ documentLabel(document) }}
                    </option>
                </select>
                <InputError :message="form.errors.invoice_document_id" />
            </div>

            <label v-if="!isProcessed" class="flex cursor-pointer items-center gap-2 text-[12px] font-bold text-[#10393b]">
                <input v-model="form.mark_processed" type="checkbox" class="size-3.5 accent-[#01b990]" />
                Abrechnung als verarbeitet markieren
            </label>

            <button
                type="submit"
                :disabled="form.processing"
                class="self-end rounded-[13px] bg-[#10393b] px-4 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
            >
                {{ form.processing ? 'Speichert...' : 'Abrechnung speichern' }}
            </button>
        </form>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
