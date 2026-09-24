<script setup lang="ts">
/**
 * Repair positions of the initial appraisal (b2b.txt §8). The whole set is
 * submitted at once and reconciled server-side, so adding, editing, reordering
 * and removing rows are all the same save.
 *
 * Amounts are net only, in both channels: net is what the workshop quotes
 * against, and the gross a customer eventually sees is the offer layer's job.
 *
 * Rendered for B2B and B2C alike. The damage images offered are the order's own
 * report documents, and the server re-derives that list rather than trusting
 * the ids posted back.
 *
 * Editable only while the server accepts positions (`editable`: before an
 * offer is accepted, in confirmed / vehicle_collected / inspected). A position
 * a workshop has already priced cannot be deleted; the server refuses that
 * under the `positions` key, shown next to the save action.
 */
import DamageImagePicker from '@/components/admin/DamageImagePicker.vue';
import RequiredMark from '@/components/form/RequiredMark.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import type { AdminAppraisalPosition, AdminAppraisalTotals, AdminReportDocument } from '@/types/admin';
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import MdiChevronDown from '~icons/mdi/chevron-down';
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
    /** AdminOrderDetail.editable.positions. */
    editable: boolean;
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

// Function form: `form.reset()` re-reads `props.positions`, which is how the
// form picks up the server's ids after a save.
const form = useForm<{ positions: PositionRow[] }>(() => ({ positions: props.positions.map(toRow) }));

/**
 * Description and damage images live in a detail row that is collapsed by
 * default — both are optional, and rendering an empty textarea per position
 * turned a list of four into a page of blank boxes. A position that already
 * carries either starts open, so nothing filled in is hidden behind a chevron.
 */
function initiallyOpenRows(positions: AdminAppraisalPosition[]): Set<number> {
    return new Set(positions.flatMap((position, index) => (position.damage_description || position.damage_image_document_ids.length ? [index] : [])));
}

const openRows = ref(initiallyOpenRows(props.positions));

/**
 * Re-seeds the form from the stored positions. Without this a saved new row
 * kept `id: null` in the form (Inertia preserves component state), so the
 * next save deleted and re-created it — cascading away every workshop price
 * attached to it.
 */
function reseedFromProps() {
    form.reset();
    form.clearErrors();
    openRows.value = initiallyOpenRows(props.positions);
}

// Fresh positions from elsewhere (a live update, another card's save) replace a
// form the admin has not touched; an edit in progress is left alone.
watch(
    () => props.positions,
    () => {
        if (!form.isDirty && !form.processing) {
            reseedFromProps();
        }
    },
);

function toggleRow(index: number) {
    const next = new Set(openRows.value);

    if (next.has(index)) {
        next.delete(index);
    } else {
        next.add(index);
    }

    openRows.value = next;
}

function hasDetail(row: PositionRow): boolean {
    return !!row.damage_description || row.damage_image_document_ids.length > 0;
}

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

    return Number.isFinite(amount) ? new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(amount as number) : '—';
}

function addPosition() {
    if (!props.editable) {
        return;
    }

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

    // The open set is keyed by row index, so everything below the removed row
    // shifts up with it.
    openRows.value = new Set([...openRows.value].filter((row) => row !== index).map((row) => (row > index ? row - 1 : row)));
}

function error(index: number, field: string): string | undefined {
    return form.errors[`positions.${index}.${field}` as keyof typeof form.errors] as string | undefined;
}

function imageError(index: number): string | undefined {
    const prefix = `positions.${index}.damage_image_document_ids`;
    const errors = form.errors as Record<string, string | undefined>;
    const key = Object.keys(errors).find((field) => field === prefix || field.startsWith(`${prefix}.`));

    return key ? errors[key] : undefined;
}

const imageDocumentIds = computed(() => new Set(props.reportDocuments.filter((document) => document.is_image).map((document) => document.id)));

function detailToggleLabel(row: PositionRow): string {
    const count = row.damage_image_document_ids.filter((id) => imageDocumentIds.value.has(id)).length;

    if (count === 0) {
        return 'Beschreibung und Schadenbilder';
    }

    return count === 1 ? 'Beschreibung und Schadenbilder (1 Bild verknüpft)' : `Beschreibung und Schadenbilder (${count} Bilder verknüpft)`;
}

function submit() {
    if (!props.editable) {
        return;
    }

    form.put(route('admin.orders.appraisal-positions', props.orderId), {
        preserveScroll: true,
        // Awaited by useForm before it snapshots the defaults, so the re-seeded
        // rows (with their server ids) become the new clean state. nextTick lets
        // the fresh page props reach this component first.
        onSuccess: async () => {
            await nextTick();
            reseedFromProps();
        },
    });
}
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex flex-wrap items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#4FA3A6]/15 text-[#2c7a7d]">
                <MdiClipboardListOutline class="size-[17px]" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Gutachtenpositionen</h2>
                <p class="mt-0.5 text-[11.5px] font-medium text-[#9bb0af]">Erstgutachten · Nettobeträge · manuell erfasst</p>
            </div>

            <p v-if="storedTotals" class="shrink-0 text-[11.5px] font-medium text-[#9bb0af]">
                Gespeichert:
                <span class="font-bold text-[#10393b]">{{ storedTotals.count }}</span>
                · <span class="font-bold text-[#10393b] tabular-nums">{{ formatEuro(storedTotals.original_total_net) }}</span> Gutachten ·
                <span class="font-bold text-[#10393b] tabular-nums">{{ formatEuro(storedTotals.chargeable_total_net) }}</span> anrechenbar
            </p>
        </div>

        <p v-if="!editable" class="mb-3 rounded-[11px] bg-[#f6f9f8] px-3 py-2 text-[11.5px] text-[#6f8585]">
            Positionen können nur bis zur Annahme eines Angebots und nur zwischen Auftragsbestätigung und Begutachtung bearbeitet werden.
        </p>

        <form class="flex flex-col gap-3" @submit.prevent="submit">
            <p v-if="!form.positions.length" class="rounded-[13px] bg-[#f6f9f8] py-8 text-center text-[12.5px] text-[#9bb0af]">
                Noch keine Positionen erfasst.
            </p>

            <!--
                A table rather than a stack of cards: the labels are said once in the
                header from `lg` up, each position is a single line, and the optional
                description / damage-image picker sit in a detail row behind the
                chevron. Below `lg` the header is dropped and the per-field labels
                (`lg:sr-only`) come back, so a row degrades into a small form.

                The header's spacer widths mirror the row's index badge and its two
                trailing buttons, which is what keeps the two grids in the same
                columns.
            -->
            <div v-else class="overflow-hidden rounded-[16px] border border-[#eef3f2]">
                <div class="hidden bg-[#f8faf9] px-3 py-2 lg:flex lg:items-center lg:gap-3">
                    <span class="w-6 shrink-0"></span>
                    <div class="grid flex-1 gap-x-3 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1.2fr)_132px_132px]">
                        <span class="admin-position-th">Bauteil / Position<RequiredMark /></span>
                        <span class="admin-position-th">Reparaturweg</span>
                        <span class="admin-position-th">Gutachten netto (€)<RequiredMark /></span>
                        <span class="admin-position-th">Anrechenbar netto (€)</span>
                    </div>
                    <span class="w-[68px] shrink-0"></span>
                </div>

                <div v-for="(row, index) in form.positions" :key="row.id ?? `new-${index}`" class="border-b border-[#f2f6f5] last:border-b-0">
                    <div class="flex items-start gap-3 p-3 lg:items-center lg:py-2">
                        <span
                            class="mt-[26px] flex h-6 w-6 shrink-0 items-center justify-center rounded-[8px] bg-[#f4f7f6] text-[11px] font-bold text-[#6f8585] lg:mt-0"
                        >
                            {{ index + 1 }}
                        </span>

                        <div class="grid min-w-0 flex-1 gap-x-3 gap-y-2 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1.2fr)_132px_132px]">
                            <div class="flex min-w-0 flex-col gap-1">
                                <label class="text-[12px] font-bold text-[#10393b] lg:sr-only">Bauteil / Position<RequiredMark /></label>
                                <Input v-model="row.component" :disabled="!editable" placeholder="z. B. Stoßfänger vorne" />
                                <InputError :message="error(index, 'component')" />
                            </div>

                            <div class="flex min-w-0 flex-col gap-1">
                                <label class="text-[12px] font-bold text-[#10393b] lg:sr-only">Reparaturweg</label>
                                <Input v-model="row.repair_method" :disabled="!editable" placeholder="z. B. Lackierung" />
                                <InputError :message="error(index, 'repair_method')" />
                            </div>

                            <div class="flex min-w-0 flex-col gap-1">
                                <label class="text-[12px] font-bold text-[#10393b] lg:sr-only">Gutachten netto (€)<RequiredMark /></label>
                                <Input
                                    v-model="row.original_amount_net"
                                    :disabled="!editable"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    inputmode="decimal"
                                    class="tabular-nums"
                                />
                                <InputError :message="error(index, 'original_amount_net')" />
                            </div>

                            <div class="flex min-w-0 flex-col gap-1">
                                <label class="text-[12px] font-bold text-[#10393b] lg:sr-only">Anrechenbar netto (€)</label>
                                <Input
                                    v-model="row.chargeable_amount_net"
                                    :disabled="!editable"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    inputmode="decimal"
                                    placeholder="wie Gutachten"
                                    class="tabular-nums"
                                />
                                <InputError :message="error(index, 'chargeable_amount_net')" />
                            </div>
                        </div>

                        <div class="mt-[26px] flex shrink-0 items-center gap-1 lg:mt-0">
                            <button
                                type="button"
                                class="relative flex h-8 w-8 items-center justify-center rounded-[9px] transition-all hover:bg-[#f4f7f6]"
                                :class="openRows.has(index) ? 'text-[#10393b]' : 'text-[#bcccca]'"
                                :aria-expanded="openRows.has(index)"
                                :title="detailToggleLabel(row)"
                                @click="toggleRow(index)"
                            >
                                <MdiChevronDown class="size-[17px] transition-transform" :class="openRows.has(index) ? 'rotate-180' : ''" />
                                <span
                                    v-if="hasDetail(row) && !openRows.has(index)"
                                    class="absolute top-1 right-1 h-1.5 w-1.5 rounded-full bg-[#01b990]"
                                ></span>
                            </button>

                            <button
                                v-if="editable"
                                type="button"
                                class="flex h-8 w-8 items-center justify-center rounded-[9px] text-[#bcccca] transition-all hover:bg-[#c0392b] hover:text-white"
                                title="Position entfernen"
                                @click="removePosition(index)"
                            >
                                <MdiTrashCanOutline class="size-[15px]" />
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="openRows.has(index)"
                        class="grid gap-4 border-t border-[#f6f9f8] bg-[#fcfdfd] p-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)] lg:pl-12"
                    >
                        <div class="flex min-w-0 flex-col gap-1">
                            <label class="text-[11px] font-bold tracking-[0.04em] text-[#9bb0af] uppercase">Schadenbeschreibung</label>
                            <textarea
                                v-model="row.damage_description"
                                :disabled="!editable"
                                rows="2"
                                class="w-full resize-y rounded-[13px] border border-[#e9efee] bg-white px-3 py-2 text-[12.5px] outline-none focus:border-[#01b990]"
                                placeholder="Optional"
                            />
                            <InputError :message="error(index, 'damage_description')" />
                        </div>

                        <div class="flex min-w-0 flex-col gap-1">
                            <span class="text-[11px] font-bold tracking-[0.04em] text-[#9bb0af] uppercase">Schadenbilder</span>
                            <DamageImagePicker
                                v-model="row.damage_image_document_ids"
                                :documents="reportDocuments"
                                :disabled="!editable"
                                :label="`Schadenbilder Position ${index + 1}`"
                            />
                            <InputError :message="imageError(index)" />
                        </div>
                    </div>
                </div>
            </div>

            <button
                v-if="editable"
                type="button"
                class="flex items-center justify-center gap-1.5 rounded-[13px] border border-dashed border-[#cbd9d7] py-2.5 text-[12.5px] font-bold text-[#00856a] transition-colors hover:bg-[#f6f9f8]"
                @click="addPosition"
            >
                <MdiPlus class="size-[15px]" />
                Position hinzufügen
            </button>

            <p
                v-if="form.errors.positions"
                role="alert"
                class="rounded-[11px] border border-[#c0392b]/25 bg-[#c0392b]/5 px-3 py-2 text-[12px] font-bold text-[#c0392b]"
            >
                {{ form.errors.positions }}
            </p>

            <!--
                Totals and the save action share one bar. The figures sit right next to
                the button they belong to instead of spanning the full width of the
                card, which at this width read as three stray numbers.
            -->
            <div v-if="form.positions.length" class="flex flex-wrap items-center justify-end gap-x-6 gap-y-3 rounded-[13px] bg-[#f6f9f8] px-4 py-3">
                <dl class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <div class="flex items-baseline gap-2">
                        <dt class="text-[11px] font-bold tracking-[0.04em] text-[#9bb0af] uppercase">Gutachten netto</dt>
                        <dd class="text-[13px] font-extrabold text-[#10393b] tabular-nums">{{ formatEuro(draftTotals.original) }}</dd>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <dt class="text-[11px] font-bold tracking-[0.04em] text-[#9bb0af] uppercase">Anrechenbar netto</dt>
                        <dd class="text-[13px] font-extrabold text-[#10393b] tabular-nums">{{ formatEuro(draftTotals.chargeable) }}</dd>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <dt class="text-[11px] font-bold tracking-[0.04em] text-[#9bb0af] uppercase">Differenz</dt>
                        <dd class="text-[13px] font-extrabold text-[#00856a] tabular-nums">{{ formatEuro(savingNet) }}</dd>
                    </div>
                </dl>

                <button
                    v-if="editable"
                    type="submit"
                    :disabled="form.processing || !isDirty"
                    class="shrink-0 rounded-[13px] bg-[#10393b] px-5 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
                >
                    {{ form.processing ? 'Speichert...' : 'Positionen speichern' }}
                </button>
            </div>

            <button
                v-else-if="editable"
                type="submit"
                :disabled="form.processing || !isDirty"
                class="self-end rounded-[13px] bg-[#10393b] px-5 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
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

.admin-position-th {
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #9bb0af;
}
</style>
