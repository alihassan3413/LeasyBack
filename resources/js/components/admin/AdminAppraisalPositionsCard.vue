<script setup lang="ts">
/**
 * Repair positions of the initial appraisal (b2b.txt §8). The whole set is
 * submitted at once and reconciled server-side, so adding, editing, reordering
 * and removing rows are all the same save.
 *
 * Amounts are net only — §9 forbids gross anywhere in the B2B quotation
 * process, so no gross column exists here or in the payload.
 *
 * Rendered only for B2B orders; the endpoint 404s for a B2C order regardless
 * of what this card offers.
 */
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import type { AdminAppraisalPosition, AdminAppraisalTotals, AdminReportDocument } from '@/types/admin';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MdiClipboardListOutline from '~icons/mdi/clipboard-list-outline';
import MdiPlus from '~icons/mdi/plus';
import MdiTrashCanOutline from '~icons/mdi/trash-can-outline';

interface PositionRow {
    id: string | null;
    component: string;
    damage_description: string;
    original_amount_net: string;
    chargeable_amount_net: string;
    repair_method: string;
    damage_image_document_ids: string[];
}

const props = defineProps<{
    orderId: string;
    positions: AdminAppraisalPosition[];
    totals: AdminAppraisalTotals | null;
    reportDocuments: AdminReportDocument[];
}>();

function toRow(position: AdminAppraisalPosition): PositionRow {
    return {
        id: position.id,
        component: position.component,
        damage_description: position.damage_description ?? '',
        original_amount_net: position.original_amount_net ?? '',
        chargeable_amount_net: position.chargeable_amount_net ?? '',
        repair_method: position.repair_method ?? '',
        damage_image_document_ids: [...position.damage_image_document_ids],
    };
}

const form = useForm<{ positions: PositionRow[] }>(() => ({ positions: props.positions.map(toRow) }));

const storedTotals = computed(() => props.totals);

const draftTotals = computed(() => {
    let original = 0;
    let chargeable = 0;

    for (const row of form.positions) {
        const originalAmount = Number.parseFloat(row.original_amount_net);
        const chargeableAmount = Number.parseFloat(row.chargeable_amount_net);

        if (Number.isFinite(originalAmount)) {
            original += originalAmount;
        }

        chargeable += Number.isFinite(chargeableAmount) ? chargeableAmount : Number.isFinite(originalAmount) ? originalAmount : 0;
    }

    return { original, chargeable };
});

const savingNet = computed(() => draftTotals.value.original - draftTotals.value.chargeable);

const isDirty = computed(() => form.isDirty);

function formatEuro(value: number | string | null | undefined): string {
    const amount = typeof value === 'string' ? Number.parseFloat(value) : (value ?? 0);

    return Number.isFinite(amount)
        ? new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(amount as number)
        : '—';
}

function addPosition() {
    form.positions.push({
        id: null,
        component: '',
        damage_description: '',
        original_amount_net: '',
        chargeable_amount_net: '',
        repair_method: '',
        damage_image_document_ids: [],
    });
}

function removePosition(index: number) {
    form.positions.splice(index, 1);
}

function toggleImage(row: PositionRow, documentId: string) {
    const at = row.damage_image_document_ids.indexOf(documentId);

    if (at === -1) {
        row.damage_image_document_ids.push(documentId);
    } else {
        row.damage_image_document_ids.splice(at, 1);
    }
}

function documentLabel(document: AdminReportDocument): string {
    return document.document_title || document.document_type || 'Dokument';
}

function error(index: number, field: string): string | undefined {
    return form.errors[`positions.${index}.${field}` as keyof typeof form.errors] as string | undefined;
}

function submit() {
    form.put(route('admin.orders.appraisal-positions', props.orderId), { preserveScroll: true });
}
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#4FA3A6]/15 text-[#2c7a7d]">
                <MdiClipboardListOutline class="size-[17px]" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Gutachtenpositionen</h2>
                <p class="mt-0.5 text-[11.5px] font-medium text-[#9bb0af]">
                    Erstgutachten · Nettobeträge · manuell erfasst
                </p>
            </div>
        </div>

        <dl v-if="storedTotals" class="mb-4 flex flex-col">
            <div class="flex items-center justify-between gap-3 border-b border-[#f2f6f5] py-2">
                <dt class="text-[12px] font-medium text-[#9bb0af]">Gespeicherte Positionen</dt>
                <dd class="text-[12.5px] font-bold text-[#10393b]">{{ storedTotals.count }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3 border-b border-[#f2f6f5] py-2">
                <dt class="text-[12px] font-medium text-[#9bb0af]">Gutachten netto</dt>
                <dd class="text-[12.5px] font-bold text-[#10393b]">{{ formatEuro(storedTotals.original_total_net) }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3 py-2">
                <dt class="text-[12px] font-medium text-[#9bb0af]">Anrechenbar netto</dt>
                <dd class="text-[12.5px] font-bold text-[#10393b]">{{ formatEuro(storedTotals.chargeable_total_net) }}</dd>
            </div>
        </dl>

        <form class="flex flex-col gap-3" @submit.prevent="submit">
            <p v-if="!form.positions.length" class="rounded-[13px] bg-[#f6f9f8] py-8 text-center text-[12.5px] text-[#9bb0af]">
                Noch keine Positionen erfasst.
            </p>

            <div
                v-for="(row, index) in form.positions"
                :key="row.id ?? `new-${index}`"
                class="flex flex-col gap-2 rounded-[13px] border border-[#e9efee] p-3"
            >
                <div class="flex items-center gap-2">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-[8px] bg-[#f4f7f6] text-[11px] font-bold text-[#6f8585]">
                        {{ index + 1 }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <Input v-model="row.component" placeholder="Bauteil / Position" />
                    </div>
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[9px] text-[#bcccca] transition-all hover:bg-[#c0392b] hover:text-white"
                        title="Position entfernen"
                        @click="removePosition(index)"
                    >
                        <MdiTrashCanOutline class="size-[15px]" />
                    </button>
                </div>
                <InputError :message="error(index, 'component')" />

                <textarea
                    v-model="row.damage_description"
                    rows="2"
                    class="w-full resize-none rounded-[13px] border border-[#e9efee] px-3 py-2 text-[12.5px] outline-none focus:border-[#01b990]"
                    placeholder="Schadenbeschreibung (optional)"
                />
                <InputError :message="error(index, 'damage_description')" />

                <div class="grid grid-cols-2 gap-2">
                    <div class="flex flex-col gap-1">
                        <label class="text-[12px] font-bold text-[#10393b]">Gutachten netto (€)</label>
                        <Input v-model="row.original_amount_net" type="number" step="0.01" min="0" inputmode="decimal" />
                        <InputError :message="error(index, 'original_amount_net')" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-[12px] font-bold text-[#10393b]">Anrechenbar netto (€)</label>
                        <Input
                            v-model="row.chargeable_amount_net"
                            type="number"
                            step="0.01"
                            min="0"
                            inputmode="decimal"
                            placeholder="wie Gutachten"
                        />
                        <InputError :message="error(index, 'chargeable_amount_net')" />
                    </div>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-[12px] font-bold text-[#10393b]">Reparaturweg</label>
                    <Input v-model="row.repair_method" placeholder="z. B. Lackierung, Instandsetzung" />
                    <InputError :message="error(index, 'repair_method')" />
                </div>

                <details v-if="reportDocuments.length" class="rounded-[11px] bg-[#f9fbfa] px-3 py-2">
                    <summary class="cursor-pointer text-[12px] font-bold text-[#10393b]">
                        Schadenbilder ({{ row.damage_image_document_ids.length }})
                    </summary>
                    <div class="mt-2 flex flex-col gap-1.5">
                        <label
                            v-for="document in reportDocuments"
                            :key="document.id"
                            class="flex cursor-pointer items-center gap-2 text-[12px] text-[#10393b]"
                        >
                            <input
                                type="checkbox"
                                :checked="row.damage_image_document_ids.includes(document.id)"
                                class="size-3.5 accent-[#01b990]"
                                @change="toggleImage(row, document.id)"
                            />
                            <span class="truncate">{{ documentLabel(document) }}</span>
                        </label>
                    </div>
                </details>
            </div>

            <button
                type="button"
                class="flex items-center justify-center gap-1.5 rounded-[13px] border border-dashed border-[#cbd9d7] py-2.5 text-[12.5px] font-bold text-[#00856a] transition-colors hover:bg-[#f6f9f8]"
                @click="addPosition"
            >
                <MdiPlus class="size-[15px]" />
                Position hinzufügen
            </button>

            <InputError :message="form.errors.positions" />

            <dl v-if="form.positions.length" class="flex flex-col rounded-[13px] bg-[#f6f9f8] px-3 py-2">
                <div class="flex items-center justify-between gap-3 py-1">
                    <dt class="text-[12px] font-medium text-[#6f8585]">Gutachten netto</dt>
                    <dd class="text-[12.5px] font-bold text-[#10393b]">{{ formatEuro(draftTotals.original) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-1">
                    <dt class="text-[12px] font-medium text-[#6f8585]">Anrechenbar netto</dt>
                    <dd class="text-[12.5px] font-bold text-[#10393b]">{{ formatEuro(draftTotals.chargeable) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 border-t border-[#e4ecea] py-1">
                    <dt class="text-[12px] font-bold text-[#6f8585]">Differenz</dt>
                    <dd class="text-[12.5px] font-extrabold text-[#00856a]">{{ formatEuro(savingNet) }}</dd>
                </div>
            </dl>

            <button
                type="submit"
                :disabled="form.processing || !isDirty"
                class="self-end rounded-[13px] bg-[#10393b] px-4 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
            >
                {{ form.processing ? 'Speichert...' : 'Positionen speichern' }}
            </button>
        </form>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
