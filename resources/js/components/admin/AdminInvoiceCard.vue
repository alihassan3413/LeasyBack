<script setup lang="ts">
/**
 * The whole B2B invoice step in one card (b2b.txt §13, §21).
 *
 * It replaces the earlier split between a Lexware card and a separate
 * Abrechnung form, which asked the admin for a free-text invoice number and
 * offered a document dropdown listing the order's Gutachten — everything
 * except an invoice. Here there is one question, asked once: how does this
 * order get its invoice?
 *
 * Two answers, then the same publish flow every other document has:
 *
 *   1. Lexware — create the draft, accounting reviews it in Lexware (§13
 *      requires it stay a draft until then), finalize it, which is the first
 *      moment it has an invoice number and a PDF at all.
 *   2. Manuell — upload the invoice PDF directly.
 *
 * Either way the document lands unpublished, and one button publishes it to
 * the company and closes the billing — the fact §21's completion gate reads.
 */
import InputError from '@/components/InputError.vue';
import UploadReportDocumentModal from '@/components/admin/UploadReportDocumentModal.vue';
import { Input } from '@/components/ui/input';
import { INVOICE_DOCUMENT_TYPE } from '@/lib/documentTypes';
import { formatPortalDateTimeShort } from '@/lib/portalDate';
import type { AdminB2bLexwareDraft, AdminOrderBilling, AdminReportDocument } from '@/types/admin';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MdiCloudUploadOutline from '~icons/mdi/cloud-upload-outline';
import MdiOpenInNew from '~icons/mdi/open-in-new';
import MdiPlus from '~icons/mdi/plus';
import MdiReceiptTextOutline from '~icons/mdi/receipt-text-outline';
import MdiTrashCanOutline from '~icons/mdi/trash-can-outline';

const props = defineProps<{
    orderId: string;
    auftragsnummer: string;
    vehicleId: string;
    billing: AdminOrderBilling;
    /** Null until the Lexware draft exists. Only one is ever created per order. */
    lexwareDraft: AdminB2bLexwareDraft | null;
    reportDocuments: AdminReportDocument[];
    /** AdminOrderDetail.editable.billing — `vehicle_returned` / `invoice_processed`. */
    editable: boolean;
}>();

const uploadOpen = ref(false);

const attachmentUploadOpen = ref(false);

const isProcessed = computed(() => props.billing.is_processed);

/** The Lexware PDF once finalize() filed it — matched by id, not by type. */
const lexwareDocument = computed(() => props.reportDocuments.find((doc) => doc.id === props.lexwareDraft?.document_id) ?? null);

/**
 * The invoice behind this order: the Lexware PDF, the one already recorded on
 * the billing, or a manually uploaded invoice — in that order of authority.
 */
const invoiceDocument = computed(
    () =>
        lexwareDocument.value ??
        props.reportDocuments.find((doc) => doc.id === props.billing.invoice_document_id) ??
        props.reportDocuments.find((doc) => doc.document_type === INVOICE_DOCUMENT_TYPE) ??
        null,
);


const invoiceAttachments = computed(() =>
    props.reportDocuments.filter(
        (doc) => doc.document_type === 'rechnung_anlage'
    )
);

const invoiceNumber = computed(() => props.lexwareDraft?.voucher_number ?? props.billing.invoice_reference ?? null);

/** Which of the four steps this order stands on — the card shows one at a time. */
const stage = computed<'create' | 'finalize' | 'publish' | 'done'>(() => {
    if (isProcessed.value) {
        return 'done';
    }

    if (invoiceDocument.value) {
        return 'publish';
    }

    return props.lexwareDraft ? 'finalize' : 'create';
});

const draftForm = useForm<{ additional_positions: { name: string; amount_net: string }[] }>({
    additional_positions: [],
});

const finalizeForm = useForm({});

const completeForm = useForm<{ invoice_document_id: string | null; mark_processed: boolean; publish_invoice_document: boolean }>({
    invoice_document_id: null,
    mark_processed: true,
    publish_invoice_document: true,
});

/** The server rejects a Lexware step as a whole, under this key — see B2bLexwareDraftService::refuse(). */
const draftError = computed(() => (draftForm.errors as Record<string, string | undefined>).lexware);
const finalizeError = computed(() => (finalizeForm.errors as Record<string, string | undefined>).lexware);

function addPosition() {
    draftForm.additional_positions.push({ name: '', amount_net: '' });
}

function removePosition(index: number) {
    draftForm.additional_positions.splice(index, 1);
}

function positionError(index: number, field: 'name' | 'amount_net'): string | undefined {
    return draftForm.errors[`additional_positions.${index}.${field}` as keyof typeof draftForm.errors] as string | undefined;
}

function createDraft() {
    draftForm.post(route('admin.orders.billing.lexware-draft', props.orderId), { preserveScroll: true });
}

function finalize() {
    finalizeForm.post(route('admin.orders.billing.lexware-finalize', props.orderId), { preserveScroll: true });
}

/**
 * Publishing the invoice and closing the billing are one action here, because
 * to the admin they are one fact: the company has its invoice.
 */
function publishAndComplete() {
    const document = invoiceDocument.value;

    if (!document) {
        return;
    }

    completeForm.invoice_document_id = document.id;
    completeForm.publish_invoice_document = !document.published;
    completeForm.patch(route('admin.orders.billing', props.orderId), { preserveScroll: true });
}

function formatDateTime(value: string | null): string {
    return formatPortalDateTimeShort(value) || '—';
}
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex items-center gap-2.5">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[11px] bg-[#4FA3A6]/15 text-[#2c7a7d]">
                <MdiReceiptTextOutline class="size-[17px]" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Rechnung</h2>
                <p class="mt-0.5 text-[11.5px] font-medium text-[#9bb0af]">
                    {{ isProcessed ? `Abgerechnet am ${formatDateTime(billing.processed_at)}` : 'Noch nicht abgerechnet' }}
                </p>
            </div>
            <span
                class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-bold"
                :class="isProcessed ? 'bg-[#01B990]/10 text-[#00856a]' : 'bg-[#f4f7f6] text-[#6f8585]'"
            >
                {{ isProcessed ? 'Abgerechnet' : 'Offen' }}
            </span>
        </div>

        <!-- Every branch below is an action, so the one blocker that stops all of them is stated once, up front. -->
        <p v-if="!editable && !isProcessed" class="rounded-[11px] bg-[#f6f9f8] px-3 py-2 text-[11.5px] text-[#6f8585]">
            Die Rechnung kann erst nach der Rückgabe an den Leasinggeber und vor dem Abschluss des Auftrags bearbeitet werden.
        </p>

        <template v-else>
            <!-- The invoice itself, as soon as there is one to name. -->
            <dl v-if="invoiceNumber || invoiceDocument" class="mb-3 flex flex-col">
                <div v-if="invoiceNumber" class="flex items-center justify-between gap-3 border-b border-[#f2f6f5] py-2">
                    <dt class="text-[12px] font-medium text-[#6f8585]">Rechnungsnummer</dt>
                    <dd class="text-[12.5px] font-bold text-[#10393b] tabular-nums">{{ invoiceNumber }}</dd>
                </div>

                <div v-if="invoiceDocument" class="flex items-center justify-between gap-3 border-b border-[#f2f6f5] py-2">
                    <dt class="text-[12px] font-medium text-[#6f8585]">Dokument</dt>
                    <dd>
                        <a
                            v-if="invoiceDocument.signed_url"
                            :href="invoiceDocument.signed_url"
                            target="_blank"
                            rel="noopener"
                            class="flex items-center gap-1.5 rounded-[9px] border border-[#e9efee] bg-white px-2.5 py-1 text-[11.5px] font-bold text-[#10393b] transition-all hover:border-[#10393b] hover:bg-[#f4f7f6]"
                        >
                            <MdiOpenInNew class="size-[13px]" />
                            Öffnen
                        </a>
                        <span v-else class="text-[11.5px] text-[#9bb0af]">—</span>
                    </dd>
                </div>

                <div v-if="invoiceDocument" class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-[12px] font-medium text-[#6f8585]">Für das Unternehmen sichtbar</dt>
                    <dd class="text-[12.5px] font-bold" :class="invoiceDocument.published ? 'text-[#00856a]' : 'text-[#6f8585]'">
                        {{ invoiceDocument.published ? 'Ja' : 'Nein' }}
                    </dd>
                </div>

                <div
    v-if="invoiceAttachments.length"
    class="mt-3 border-t border-[#f2f6f5] pt-3"
>
    <h3 class="mb-2 text-[12px] font-bold text-[#10393b]">
        Zusätzliche Dokumente
    </h3>

    <div
        v-for="document in invoiceAttachments"
        :key="document.id"
        class="flex items-center justify-between gap-3 border-b border-[#f2f6f5] py-2"
    >
        <span class="text-[12px] font-medium text-[#10393b]">
            {{ document.document_title || 'Zusatzdokument' }}
        </span>

        <a
            v-if="document.signed_url"
            :href="document.signed_url"
            target="_blank"
            rel="noopener"
            class="flex items-center gap-1 rounded-[9px] border border-[#e9efee] px-2 py-1 text-[11.5px] font-bold text-[#10393b]"
        >
            <MdiOpenInNew class="size-[13px]" />
            Öffnen
        </a>
    </div>
</div>
                
            </dl>

            <!-- 1. Nothing yet: the one question the card exists to ask. -->
            <form v-if="stage === 'create'" class="flex flex-col gap-3" @submit.prevent="createDraft">
                <div v-if="draftForm.additional_positions.length" class="flex flex-col gap-2">
                    <div v-for="(position, index) in draftForm.additional_positions" :key="index" class="flex items-start gap-2">
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <label class="text-[12px] font-bold text-[#10393b]">Bezeichnung</label>
                            <Input v-model="position.name" placeholder="z. B. Transport" />
                            <InputError :message="positionError(index, 'name')" />
                        </div>
                        <div class="flex w-32 shrink-0 flex-col gap-1">
                            <label class="text-[12px] font-bold text-[#10393b]">Netto (€)</label>
                            <Input v-model="position.amount_net" type="number" step="0.01" min="0" inputmode="decimal" class="tabular-nums" />
                            <InputError :message="positionError(index, 'amount_net')" />
                        </div>
                        <button
                            type="button"
                            class="mt-[26px] flex h-8 w-8 shrink-0 items-center justify-center rounded-[9px] text-[#bcccca] transition-all hover:bg-[#c0392b] hover:text-white"
                            title="Position entfernen"
                            @click="removePosition(index)"
                        >
                            <MdiTrashCanOutline class="size-[15px]" />
                        </button>
                    </div>
                </div>

                <button
                    type="button"
                    class="flex items-center justify-center gap-1.5 rounded-[13px] border border-dashed border-[#cbd9d7] py-2 text-[12px] font-bold text-[#00856a] transition-colors hover:bg-[#f6f9f8]"
                    @click="addPosition"
                >
                    <MdiPlus class="size-[14px]" />
                    Zusätzliche Position
                </button>

                <InputError :message="draftError" />

                <button
                    type="submit"
                    :disabled="draftForm.processing"
                    class="rounded-[13px] bg-[#10393b] px-4 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
                >
                    {{ draftForm.processing ? 'Wird erstellt...' : 'Rechnung in Lexware erstellen' }}
                </button>

                <div class="flex items-center gap-3">
                    <span class="h-px flex-1 bg-[#eef3f2]"></span>
                    <span class="text-[11px] font-bold text-[#bcccca]">oder</span>
                    <span class="h-px flex-1 bg-[#eef3f2]"></span>
                </div>

                <button
                    type="button"
                    class="flex items-center justify-center gap-1.5 rounded-[13px] border border-[#e9efee] bg-white py-2.5 text-[12.5px] font-bold text-[#10393b] transition-all hover:border-[#10393b] hover:bg-[#f4f7f6]"
                    @click="uploadOpen = true"
                >
                    <MdiCloudUploadOutline class="size-[15px]" />
                    Rechnung manuell hochladen
                </button>
            </form>

            <!-- 2. The draft is with accounting. §13: it stays a draft until they are done with it. -->
            <div v-else-if="stage === 'finalize'" class="flex flex-col gap-3">
                <p class="rounded-[11px] bg-[#f6f9f8] px-3 py-2 text-[11.5px] text-[#6f8585]">
                    Der Entwurf wurde am {{ formatDateTime(lexwareDraft?.submitted_at ?? null) }} in Lexware angelegt. Prüfen und finalisieren Sie ihn
                    dort — erst dann erhält die Rechnung ihre Nummer und ihr PDF. Danach hier abrufen.
                </p>

                <a
                    v-if="lexwareDraft?.lexware_url"
                    :href="lexwareDraft.lexware_url"
                    target="_blank"
                    rel="noopener"
                    class="flex items-center justify-center gap-1.5 rounded-[13px] border border-[#e9efee] bg-white py-2.5 text-[12.5px] font-bold text-[#10393b] transition-all hover:border-[#10393b] hover:bg-[#f4f7f6]"
                >
                    <MdiOpenInNew class="size-[14px]" />
                    Entwurf in Lexware öffnen
                </a>

                <InputError :message="finalizeError" />

                <button
                    type="button"
                    :disabled="finalizeForm.processing"
                    class="rounded-[13px] bg-[#10393b] px-4 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
                    @click="finalize"
                >
                    {{ finalizeForm.processing ? 'Wird abgerufen...' : 'Rechnung aus Lexware abrufen' }}
                </button>

                <button
                    type="button"
                    class="flex items-center justify-center gap-1.5 rounded-[13px] border border-[#e9efee] bg-white py-2.5 text-[12.5px] font-bold text-[#10393b] transition-all hover:border-[#10393b] hover:bg-[#f4f7f6]"
                    @click="uploadOpen = true"
                >
                    <MdiCloudUploadOutline class="size-[15px]" />
                    Stattdessen Rechnung hochladen
                </button>
            </div>

            <!-- 3. The invoice exists — one button makes it the company's and closes the billing. -->
            <div v-else-if="stage === 'publish'" class="flex flex-col gap-3">
                <button
    v-if="editable"
    type="button"
    class="flex items-center justify-center gap-1.5 rounded-[13px] border border-dashed border-[#cbd9d7] py-2 text-[12px] font-bold text-[#00856a]"
    @click="attachmentUploadOpen = true"
>
    <MdiCloudUploadOutline class="size-[15px]" />

    Zusatzdokument hochladen
</button>
                <InputError :message="completeForm.errors.invoice_document_id" />

                <button
                    type="button"
                    :disabled="completeForm.processing"
                    class="rounded-[13px] bg-[#10393b] px-4 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
                    @click="publishAndComplete"
                >
                    {{
                        completeForm.processing
                            ? 'Wird abgeschlossen...'
                            : invoiceDocument?.published
                              ? 'Abrechnung abschließen'
                              : 'Rechnung veröffentlichen & Abrechnung abschließen'
                    }}
                </button>

                <p class="text-[11.5px] text-[#6f8585]">Der Auftrag kann erst abgeschlossen werden, wenn die Abrechnung erledigt ist.</p>
            </div>

            <!-- 4. Done — the card is the record. -->
            <p v-else class="rounded-[11px] bg-[#01B990]/8 px-3 py-2 text-[11.5px] font-medium text-[#00856a]">
                Die Abrechnung ist abgeschlossen. Der Auftrag kann abgeschlossen werden.
            </p>
        </template>

        <UploadReportDocumentModal
            v-model:open="uploadOpen"
            :vehicle-id="vehicleId"
            :auftragsnummer-options="[{ value: auftragsnummer, label: auftragsnummer }]"
            :default-auftragsnummer="auftragsnummer"
            :default-document-type="INVOICE_DOCUMENT_TYPE"
        />
        <UploadReportDocumentModal
    v-model:open="attachmentUploadOpen"
    :vehicle-id="vehicleId"
    :auftragsnummer-options="[{ value: auftragsnummer, label: auftragsnummer }]"
    :default-auftragsnummer="auftragsnummer"
    :default-document-type="'rechnung_anlage'"
/>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
