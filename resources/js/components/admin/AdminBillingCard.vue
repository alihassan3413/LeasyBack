<script setup lang="ts">
/**
 * Internal B2B billing state (b2b.txt §13, §21).
 *
 * There is no accounting or payment integration behind this — it records
 * whether Leasyback has processed the billing, which is the fact the
 * completion gate reads. Marking processed is one-way: the server does not
 * offer un-marking, because a completed order would then lose the
 * justification it was completed on.
 *
 * "Processed" must point at an actual invoice — a reference, an attached
 * document, or the Lexware draft (AdminLexwareDraftCard) — and the server
 * refuses it otherwise; the form says so before the round trip. Editable only
 * in `vehicle_returned` / `invoice_processed` (`editable`); afterwards the
 * card is the read-only record.
 */
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { formatPortalDateTimeShort } from '@/lib/portalDate';
import type { AdminB2bLexwareDraft, AdminOrderBilling, AdminReportDocument } from '@/types/admin';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MdiReceiptTextOutline from '~icons/mdi/receipt-text-outline';

const props = defineProps<{
    orderId: string;
    billing: AdminOrderBilling;
    reportDocuments: AdminReportDocument[];
    /** Null until AdminLexwareDraftCard's draft exists — it alone already counts as the invoice. */
    lexwareDraft: AdminB2bLexwareDraft | null;
    /** AdminOrderDetail.editable.billing. */
    editable: boolean;
}>();

const form = useForm(() => ({
    invoice_reference: props.billing.invoice_reference ?? '',
    invoice_document_id: props.billing.invoice_document_id ?? '',
    mark_processed: props.billing.is_processed,
}));

const isProcessed = computed(() => props.billing.is_processed);

/** Mirrors B2bBillingService::update() — a reference, a document, or the Lexware draft, any one is enough. */
const hasInvoice = computed(() => form.invoice_reference.trim() !== '' || form.invoice_document_id !== '' || props.lexwareDraft !== null);
const invoiceMissing = computed(() => (form.mark_processed || isProcessed.value) && !hasInvoice.value);
const canSubmit = computed(() => props.editable && !form.processing && !invoiceMissing.value);

function formatDateTime(value: string | null): string {
    return formatPortalDateTimeShort(value) || '—';
}

function documentLabel(document: AdminReportDocument): string {
    return document.document_title || document.document_type || 'Dokument';
}

function submit() {
    if (!canSubmit.value) {
        return;
    }

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

        <p v-if="!editable" class="mb-3 rounded-[11px] bg-[#f6f9f8] px-3 py-2 text-[11.5px] text-[#6f8585]">
            Die Abrechnung kann nur nach der Rückgabe an den Leasinggeber und vor dem Abschluss des Auftrags bearbeitet werden.
        </p>

        <p v-else-if="lexwareDraft && !isProcessed" class="mb-3 rounded-[11px] bg-[#01B990]/8 px-3 py-2 text-[11.5px] font-medium text-[#00856a]">
            Der Lexware-Rechnungsentwurf{{ lexwareDraft.voucher_number ? ` ${lexwareDraft.voucher_number}` : '' }} liegt vor und zählt bereits als
            Rechnung — Rechnungsnummer und Dokument unten sind optional.
        </p>

        <form class="flex flex-col gap-3" @submit.prevent="submit">
            <fieldset :disabled="!editable" class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-col gap-1">
                    <label class="text-[12px] font-bold text-[#10393b]">Rechnungsnummer / Referenz</label>
                    <Input v-model="form.invoice_reference" :aria-invalid="!!form.errors.invoice_reference" />
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

                <p v-if="editable && invoiceMissing" class="text-[11.5px] font-medium text-[#c0392b]">
                    Für eine verarbeitete Abrechnung ist eine Rechnungsnummer oder ein Rechnungsdokument erforderlich.
                </p>

                <button
                    v-if="editable"
                    type="submit"
                    :disabled="!canSubmit"
                    class="self-end rounded-[13px] bg-[#10393b] px-4 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
                >
                    {{ form.processing ? 'Speichert...' : 'Abrechnung speichern' }}
                </button>
            </fieldset>
        </form>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
