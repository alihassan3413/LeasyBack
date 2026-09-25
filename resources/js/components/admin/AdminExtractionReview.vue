<script setup lang="ts">
import DamageImagePicker from '@/components/admin/DamageImagePicker.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import type { AdminAppraisalExtraction, AdminReportDocument } from '@/types/admin';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MdiAutoFix from '~icons/mdi/auto-fix';
import MdiCheckCircleOutline from '~icons/mdi/check-circle-outline';
import MdiClose from '~icons/mdi/close';
import MdiTextBoxOutline from '~icons/mdi/text-box-outline';

const props = defineProps<{ extraction: AdminAppraisalExtraction; reportDocuments: AdminReportDocument[] }>();

const emit = defineEmits<{ (e: 'close'): void }>();

interface ReviewRow {
    selected: boolean;
    component: string;
    damage_description: string;
    repair_method: string;
    original_amount_net: string;
    chargeable_amount_net: string;
    page_number: number | null;
    source_text: string | null;
    confidence: number | null;
    damage_number: number | null;
    damage_image_document_ids: string[];
    suggested_image_document_ids: string[];
    suggestion_strategy: string | null;
}

const form = useForm<{ positions: ReviewRow[] }>({
    positions: props.extraction.lines.map((line) => ({
        selected: true,
        component: line.component,
        damage_description: line.damage_description ?? '',
        repair_method: line.repair_method ?? '',
        original_amount_net: line.original_amount_net ?? '',
        chargeable_amount_net: line.chargeable_amount_net ?? '',
        page_number: line.page_number,
        source_text: line.source_text,
        confidence: line.confidence,
        damage_number: line.damage_number,
        damage_image_document_ids: line.suggested_images.map((image) => image.document_id),
        suggested_image_document_ids: line.suggested_images.map((image) => image.document_id),
        suggestion_strategy: line.suggested_images[0]?.strategy ?? null,
    })),
});

const imageDocuments = computed(() => props.reportDocuments.filter((document) => document.is_image));

function suggestionLabel(row: ReviewRow): string {
    return row.suggestion_strategy === 'damage_number'
        ? `Automatisch zugeordnet über Beschädigung ${row.damage_number}`
        : 'Automatisch zugeordnet über Bauteil und Reparaturweg';
}

function isUnchangedSuggestion(row: ReviewRow): boolean {
    return (
        row.suggested_image_document_ids.length === row.damage_image_document_ids.length &&
        row.suggested_image_document_ids.every((id) => row.damage_image_document_ids.includes(id))
    );
}

const openSources = ref<Set<number>>(new Set());

const selected = computed(() => form.positions.filter((row) => row.selected));
const allSelected = computed(() => selected.value.length === form.positions.length && form.positions.length > 0);

const selectedTotal = computed(() =>
    selected.value.reduce((sum, row) => {
        const amount = Number.parseFloat(row.chargeable_amount_net || row.original_amount_net);

        return Number.isFinite(amount) ? sum + amount : sum;
    }, 0),
);

function toggleAll() {
    const next = !allSelected.value;

    form.positions.forEach((row) => (row.selected = next));
}

function toggleSource(index: number) {
    const next = new Set(openSources.value);

    if (next.has(index)) {
        next.delete(index);
    } else {
        next.add(index);
    }

    openSources.value = next;
}

function formatEuro(value: number | string): string {
    const amount = typeof value === 'string' ? Number.parseFloat(value) : value;

    return Number.isFinite(amount) ? new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(amount as number) : '—';
}

function confidenceLabel(value: number | null): string {
    return value === null ? '—' : `${Math.round(value * 100)} %`;
}

function rowError(index: number, field: string): string | undefined {
    return form.errors[`positions.${index}.${field}` as keyof typeof form.errors] as string | undefined;
}

function submit() {
    if (selected.value.length === 0) {
        return;
    }

    form.transform(() => ({
        positions: form.positions
            .filter((row) => row.selected)
            .map((row) => ({
                component: row.component,
                damage_description: row.damage_description === '' ? null : row.damage_description,
                repair_method: row.repair_method === '' ? null : row.repair_method,
                original_amount_net: row.original_amount_net,
                chargeable_amount_net: row.chargeable_amount_net === '' ? null : row.chargeable_amount_net,
                damage_image_document_ids: row.damage_image_document_ids,
            })),
    })).post(route('admin.orders.appraisal-extractions.apply', props.extraction.id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <form class="mt-3 rounded-[13px] border border-[#e9efee] bg-white p-3.5" data-testid="extraction-review" @submit.prevent="submit">
        <div class="mb-3 flex flex-wrap items-center gap-2.5">
            <div class="min-w-0 flex-1">
                <h3 class="text-[13.5px] font-extrabold text-[#10393b]">Vorschlag prüfen</h3>
                <p class="mt-0.5 text-[12px] text-[#6f8585]">
                    Werte können vor der Übernahme angepasst werden. Der ausgelesene Vorschlag bleibt unverändert.
                </p>
            </div>
            <button
                type="button"
                class="flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-[11px] text-[#9bb0af] transition-colors hover:bg-[#f4f7f6] hover:text-[#10393b] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                aria-label="Prüfung schließen"
                @click="emit('close')"
            >
                <MdiClose class="size-[18px]" aria-hidden="true" />
            </button>
        </div>

        <div class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-[11px] bg-[#f6f9f8] px-3 py-2">
            <label class="flex min-h-11 cursor-pointer items-center gap-2 text-[12.5px] font-bold text-[#10393b]">
                <input
                    type="checkbox"
                    class="size-3.5 accent-[#01b990]"
                    :checked="allSelected"
                    :indeterminate="selected.length > 0 && !allSelected"
                    @change="toggleAll"
                />
                Alle auswählen
            </label>
            <p class="text-[12px] text-[#6f8585] tabular-nums" aria-live="polite">
                {{ selected.length }} von {{ form.positions.length }} ausgewählt · {{ formatEuro(selectedTotal) }}
            </p>
        </div>

        <ul class="flex flex-col gap-2">
            <li
                v-for="(row, index) in form.positions"
                :key="index"
                class="rounded-[13px] border p-3 transition-colors motion-reduce:transition-none"
                :class="row.selected ? 'border-[#01b990]/40 bg-[#01b990]/5' : 'border-[#e9efee] bg-white opacity-70'"
                data-testid="review-position"
            >
                <div class="flex items-start gap-2.5">
                    <input
                        v-model="row.selected"
                        type="checkbox"
                        class="mt-1 size-3.5 shrink-0 accent-[#01b990]"
                        :aria-label="`Position ${index + 1} übernehmen`"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-[11px] font-bold tracking-[0.04em] text-[#9bb0af] uppercase">Position {{ index + 1 }}</span>
                            <span v-if="row.page_number" class="rounded-full bg-[#f4f7f6] px-2 py-0.5 text-[10.5px] font-bold text-[#6f8585]">
                                Seite {{ row.page_number }}
                            </span>
                            <span
                                v-if="row.confidence !== null"
                                class="rounded-full px-2 py-0.5 text-[10.5px] font-bold"
                                :class="row.confidence < 0.6 ? 'bg-[#FDF1D8] text-[#9A5B00]' : 'bg-[#4FA3A6]/15 text-[#2c7a7d]'"
                            >
                                Sicherheit {{ confidenceLabel(row.confidence) }}
                            </span>
                        </div>

                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <div class="flex min-w-0 flex-col gap-1">
                                <label class="text-[11px] font-bold text-[#6f8585]">Bauteil</label>
                                <Input v-model="row.component" :disabled="!row.selected" />
                                <InputError :message="rowError(index, 'component')" />
                            </div>
                            <div class="flex min-w-0 flex-col gap-1">
                                <label class="text-[11px] font-bold text-[#6f8585]">Reparaturweg</label>
                                <Input v-model="row.repair_method" :disabled="!row.selected" />
                                <InputError :message="rowError(index, 'repair_method')" />
                            </div>
                            <div class="flex min-w-0 flex-col gap-1 sm:col-span-2">
                                <label class="text-[11px] font-bold text-[#6f8585]">Schadenbeschreibung</label>
                                <Input v-model="row.damage_description" :disabled="!row.selected" />
                                <InputError :message="rowError(index, 'damage_description')" />
                            </div>
                            <div class="flex min-w-0 flex-col gap-1">
                                <label class="text-[11px] font-bold text-[#6f8585]">Gutachten netto (€)</label>
                                <Input
                                    v-model="row.original_amount_net"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    inputmode="decimal"
                                    class="tabular-nums"
                                    :disabled="!row.selected"
                                />
                                <InputError :message="rowError(index, 'original_amount_net')" />
                            </div>
                            <div class="flex min-w-0 flex-col gap-1">
                                <label class="text-[11px] font-bold text-[#6f8585]">Anrechenbar netto (€)</label>
                                <Input
                                    v-model="row.chargeable_amount_net"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    inputmode="decimal"
                                    placeholder="wie Gutachten"
                                    class="tabular-nums"
                                    :disabled="!row.selected"
                                />
                                <InputError :message="rowError(index, 'chargeable_amount_net')" />
                            </div>
                        </div>

                        <div v-if="imageDocuments.length" class="mt-3">
                            <div class="mb-1.5 flex flex-wrap items-center gap-2">
                                <span class="text-[11px] font-bold text-[#6f8585]">Schadenbilder</span>
                                <span
                                    v-if="row.suggested_image_document_ids.length && isUnchangedSuggestion(row)"
                                    class="flex items-center gap-1 rounded-full bg-[#4FA3A6]/15 px-2 py-0.5 text-[10.5px] font-bold text-[#2c7a7d]"
                                    data-testid="suggestion-badge"
                                >
                                    <MdiAutoFix class="size-[12px]" aria-hidden="true" />
                                    {{ suggestionLabel(row) }}
                                </span>
                                <span
                                    v-else-if="row.suggested_image_document_ids.length"
                                    class="rounded-full bg-[#f4f7f6] px-2 py-0.5 text-[10.5px] font-bold text-[#6f8585]"
                                    data-testid="suggestion-badge"
                                >
                                    Vorschlag angepasst
                                </span>
                            </div>
                            <DamageImagePicker
                                v-model="row.damage_image_document_ids"
                                :documents="imageDocuments"
                                :disabled="!row.selected"
                                :label="`Schadenbilder Position ${index + 1}`"
                            />
                        </div>

                        <button
                            v-if="row.source_text"
                            type="button"
                            class="mt-2 flex min-h-11 cursor-pointer items-center gap-1.5 text-[11.5px] font-bold text-[#6f8585] transition-colors hover:text-[#10393b] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                            :aria-expanded="openSources.has(index)"
                            @click="toggleSource(index)"
                        >
                            <MdiTextBoxOutline class="size-[14px]" aria-hidden="true" />
                            Quelltext aus dem Gutachten
                        </button>
                        <p
                            v-if="openSources.has(index) && row.source_text"
                            class="mt-1 rounded-[11px] bg-[#f6f9f8] px-3 py-2 font-mono text-[11.5px] break-words text-[#6f8585]"
                        >
                            {{ row.source_text }}
                        </p>
                    </div>
                </div>
            </li>
        </ul>

        <InputError class="mt-2" :message="form.errors.positions" />

        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <p class="text-[12px] text-[#6f8585]">
                {{
                    selected.length === 0
                        ? 'Wählen Sie mindestens eine Position aus.'
                        : 'Die Positionen werden zu den bestehenden Gutachtenpositionen hinzugefügt.'
                }}
            </p>
            <button
                type="submit"
                :disabled="selected.length === 0 || form.processing"
                class="flex min-h-11 shrink-0 cursor-pointer items-center gap-1.5 rounded-[13px] bg-[#10393b] px-5 text-[13px] font-bold text-white transition-all hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] disabled:cursor-default disabled:opacity-50 motion-reduce:transition-none"
            >
                <MdiCheckCircleOutline v-if="!form.processing" class="size-[15px]" aria-hidden="true" />
                {{ form.processing ? 'Wird übernommen...' : `${selected.length} Position${selected.length === 1 ? '' : 'en'} übernehmen` }}
            </button>
        </div>
    </form>
</template>
