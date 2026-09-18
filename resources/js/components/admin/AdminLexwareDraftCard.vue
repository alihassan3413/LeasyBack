<script setup lang="ts">
/**
 * B2B Lexware invoice draft (b2b.txt §13).
 *
 * Creates the editable draft in Lexware from the accepted offer's repair
 * positions, the company's service fee, and any extra positions the admin
 * adds here. It is a draft only — accounting reviews and finalizes it inside
 * Lexware — so its PDF is filed unpublished: it shows up in "Gutachten &
 * Rechnungen" for admin immediately, but the company only sees it once
 * someone explicitly publishes it there. Only one draft is ever created per
 * order, so once it exists the form is replaced by its read-only summary.
 */
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { formatPortalDateTimeShort } from '@/lib/portalDate';
import type { AdminB2bLexwareDraft, AdminReportDocument } from '@/types/admin';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MdiOpenInNew from '~icons/mdi/open-in-new';
import MdiPlus from '~icons/mdi/plus';
import MdiReceiptTextOutline from '~icons/mdi/receipt-text-outline';
import MdiTrashCanOutline from '~icons/mdi/trash-can-outline';

const props = defineProps<{
    orderId: string;
    draft: AdminB2bLexwareDraft | null;
    reportDocuments: AdminReportDocument[];
    /** AdminOrderDetail.editable.billing — the draft shares billing's status window. */
    editable: boolean;
}>();

const form = useForm<{ additional_positions: { name: string; amount_net: string }[] }>({
    additional_positions: [],
});

const canCreate = computed(() => props.editable && props.draft === null);

const draftDocument = computed(() => props.reportDocuments.find((doc) => doc.id === props.draft?.document_id) ?? null);

function addPosition() {
    form.additional_positions.push({ name: '', amount_net: '' });
}

function removePosition(index: number) {
    form.additional_positions.splice(index, 1);
}

function positionError(index: number, field: 'name' | 'amount_net'): string | undefined {
    return form.errors[`additional_positions.${index}.${field}` as keyof typeof form.errors] as string | undefined;
}

/** The server rejects the whole draft (not a specific field) under this key — see B2bLexwareDraftService::refuse(). */
const draftError = computed(() => (form.errors as Record<string, string | undefined>).lexware);

function submit() {
    if (!canCreate.value) {
        return;
    }

    form.post(route('admin.orders.billing.lexware-draft', props.orderId), { preserveScroll: true });
}

function formatDateTime(value: string | null): string {
    return formatPortalDateTimeShort(value) || '—';
}
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#4FA3A6]/15 text-[#2c7a7d]">
                <MdiReceiptTextOutline class="size-[17px]" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Lexware-Rechnungsentwurf</h2>
                <p class="mt-0.5 text-[11.5px] font-medium text-[#9bb0af]">
                    {{ draft?.voucher_number ? `Entwurf ${draft.voucher_number}` : 'Noch kein Entwurf erstellt' }}
                </p>
            </div>
            <span v-if="draft" class="shrink-0 rounded-full bg-[#f4f7f6] px-2 py-0.5 text-[10.5px] font-bold text-[#6f8585]"> Entwurf </span>
        </div>

        <template v-if="draft">
            <dl class="flex flex-col">
                <div v-if="draft.voucher_number" class="flex items-center justify-between gap-3 border-b border-[#f2f6f5] py-2">
                    <dt class="text-[12px] font-medium text-[#6f8585]">Rechnungsnummer</dt>
                    <dd class="text-[12.5px] font-bold text-[#10393b] tabular-nums">{{ draft.voucher_number }}</dd>
                </div>

                <div v-if="draft.submitted_at" class="flex items-center justify-between gap-3 border-b border-[#f2f6f5] py-2">
                    <dt class="text-[12px] font-medium text-[#6f8585]">Erstellt am</dt>
                    <dd class="text-[12.5px] font-bold text-[#10393b] tabular-nums">{{ formatDateTime(draft.submitted_at) }}</dd>
                </div>

                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-[12px] font-medium text-[#6f8585]">Dokument</dt>
                    <dd>
                        <a
                            v-if="draftDocument?.signed_url"
                            :href="draftDocument.signed_url"
                            target="_blank"
                            rel="noopener"
                            class="flex items-center gap-1.5 rounded-[9px] border border-[#e9efee] bg-white px-2.5 py-1 text-[11.5px] font-bold text-[#10393b] transition-all hover:border-[#10393b] hover:bg-[#f4f7f6]"
                        >
                            <MdiOpenInNew class="size-[13px]" />
                            Öffnen
                        </a>
                        <span v-else class="text-[11.5px] text-[#9bb0af]">Wird erzeugt…</span>
                    </dd>
                </div>
            </dl>

            <p class="mt-3 rounded-[11px] bg-[#f6f9f8] px-3 py-2 text-[11.5px] text-[#6f8585]">
                Der Entwurf ist für das Unternehmen noch nicht sichtbar. Prüfen Sie ihn in Lexware, bevor Sie ihn versenden.
            </p>
        </template>

        <form v-else class="flex flex-col gap-3" @submit.prevent="submit">
            <p v-if="!editable" class="rounded-[11px] bg-[#f6f9f8] px-3 py-2 text-[11.5px] text-[#6f8585]">
                Der Entwurf kann erst nach der Rückgabe an den Leasinggeber erstellt werden.
            </p>

            <div v-if="form.additional_positions.length" class="flex flex-col gap-2">
                <div v-for="(position, index) in form.additional_positions" :key="index" class="flex items-start gap-2">
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <label class="text-[12px] font-bold text-[#10393b]">Bezeichnung</label>
                        <Input v-model="position.name" :disabled="!editable" placeholder="z. B. Transport" />
                        <InputError :message="positionError(index, 'name')" />
                    </div>
                    <div class="flex w-32 shrink-0 flex-col gap-1">
                        <label class="text-[12px] font-bold text-[#10393b]">Netto (€)</label>
                        <Input
                            v-model="position.amount_net"
                            :disabled="!editable"
                            type="number"
                            step="0.01"
                            min="0"
                            inputmode="decimal"
                            class="tabular-nums"
                        />
                        <InputError :message="positionError(index, 'amount_net')" />
                    </div>
                    <button
                        v-if="editable"
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
                v-if="editable"
                type="button"
                class="flex items-center justify-center gap-1.5 rounded-[13px] border border-dashed border-[#cbd9d7] py-2 text-[12px] font-bold text-[#00856a] transition-colors hover:bg-[#f6f9f8]"
                @click="addPosition"
            >
                <MdiPlus class="size-[14px]" />
                Zusätzliche Position
            </button>

            <InputError :message="draftError" />

            <button
                v-if="editable"
                type="submit"
                :disabled="form.processing"
                class="self-end rounded-[13px] bg-[#10393b] px-4 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
            >
                {{ form.processing ? 'Wird erstellt...' : 'Rechnungsentwurf in Lexware erstellen' }}
            </button>
        </form>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
