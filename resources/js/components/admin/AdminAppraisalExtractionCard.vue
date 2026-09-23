<script setup lang="ts">
import AdminExtractionReview from '@/components/admin/AdminExtractionReview.vue';
import InputError from '@/components/InputError.vue';
import { formatPortalDate, formatPortalDateTimeShort } from '@/lib/portalDate';
import type { AdminAppraisalExtraction, AdminAppraisalExtractionStatus, AdminReportDocument } from '@/types/admin';
import { useForm, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import MdiAlertCircleOutline from '~icons/mdi/alert-circle-outline';
import MdiCheck from '~icons/mdi/check';
import MdiCheckCircle from '~icons/mdi/check-circle';
import MdiChevronDown from '~icons/mdi/chevron-down';
import MdiCircleSmall from '~icons/mdi/circle-small';
import MdiClipboardCheckOutline from '~icons/mdi/clipboard-check-outline';
import MdiFileDocumentOutline from '~icons/mdi/file-document-outline';
import MdiLoading from '~icons/mdi/loading';
import MdiMagnifyScan from '~icons/mdi/magnify-scan';
import MdiPencilOutline from '~icons/mdi/pencil-outline';
import MdiRefresh from '~icons/mdi/refresh';
import MdiTextBoxSearchOutline from '~icons/mdi/text-box-search-outline';

const props = defineProps<{
    orderId: string;
    extractions: AdminAppraisalExtraction[];
    reportDocuments: AdminReportDocument[];
    editable: boolean;
}>();

const EXTRACTABLE_TYPES = ['gutachten', 'nachgutachten'];

const STATUS_LABELS: Record<AdminAppraisalExtractionStatus, string> = {
    pending: 'In Warteschlange',
    processing: 'Wird ausgelesen',
    ready: 'Vorschlag liegt vor',
    applied: 'Übernommen',
    discarded: 'Verworfen',
    failed: 'Fehlgeschlagen',
};

const STATUS_STYLES: Record<AdminAppraisalExtractionStatus, string> = {
    pending: 'bg-[#f4f7f6] text-[#6f8585]',
    processing: 'bg-[#4FA3A6]/15 text-[#2c7a7d]',
    ready: 'bg-[#01b990]/15 text-[#00856a]',
    applied: 'bg-[#01b990]/15 text-[#00856a]',
    discarded: 'bg-[#f4f7f6] text-[#9bb0af]',
    failed: 'bg-[#c0392b]/10 text-[#c0392b]',
};

const ERROR_MESSAGES: Record<string, string> = {
    unsupported_document: 'Dieses Gutachten konnte nicht gelesen werden — vermutlich ein Scan ohne Textebene oder ein unbekanntes Layout.',
    document_missing: 'Das Gutachten wurde zwischenzeitlich gelöscht.',
    document_unreadable: 'Die Datei konnte nicht aus dem Speicher gelesen werden.',
    no_extractor_available: 'Für dieses Dokument steht derzeit kein Ausleseverfahren bereit.',
    invalid_proposal: 'Die ausgelesenen Positionen waren nicht plausibel und wurden verworfen.',
    extractor_failed: 'Die Auslese wurde unterbrochen.',
    unexpected_error: 'Die Auslese ist wiederholt fehlgeschlagen.',
};

const candidates = computed(() =>
    props.reportDocuments.filter((document) => document.is_pdf && EXTRACTABLE_TYPES.includes((document.document_type ?? '').toLowerCase())),
);

const latest = computed<AdminAppraisalExtraction | null>(() => props.extractions[0] ?? null);
const history = computed(() => props.extractions.slice(1));
const isRunning = computed(() => latest.value !== null && ['pending', 'processing'].includes(latest.value.status));
const isReady = computed(() => latest.value?.status === 'ready');
const isApplied = computed(() => latest.value?.status === 'applied');
const hasFailed = computed(() => latest.value?.status === 'failed');
const canStart = computed(() => props.editable && candidates.value.length > 0 && !isRunning.value);

const isPending = computed(() => latest.value?.status === 'pending');

const runningHeadline = computed(() => (isPending.value ? 'Gutachten wurde hochgeladen' : 'Gutachten wird analysiert'));

const runningDescription = computed(() =>
    isPending.value
        ? 'Die Analyse startet in Kürze. Sie können die Seite verlassen — der Vorschlag bleibt erhalten.'
        : 'Struktur und Schadenpositionen werden aus dem PDF gelesen.',
);

const analysisSteps = computed(() => {
    const active = isPending.value ? 'waiting' : 'active';

    return [
        { key: 'received', label: 'Dokument empfangen', state: 'done' },
        { key: 'structure', label: 'Berichtsstruktur wird gelesen', state: active },
        { key: 'positions', label: 'Schadenpositionen werden ausgelesen', state: active },
        { key: 'proposal', label: 'Vorschlag wird vorbereitet', state: 'waiting' },
    ];
});

const form = useForm({ document_id: candidates.value[0]?.id ?? '' });
const historyOpen = ref(false);
const reviewOpen = ref(false);

watch(latest, (run) => {
    if (run?.status !== 'ready') {
        reviewOpen.value = false;
    }
});

watch(candidates, (documents) => {
    if (!documents.some((document) => document.id === form.document_id)) {
        form.document_id = documents[0]?.id ?? '';
    }
});

const poll = usePoll(5000, { only: ['order'] }, { keepAlive: false, autoStart: false });

watch(isRunning, (running) => (running ? poll.start() : poll.stop()), { immediate: true });

function documentLabel(document: AdminReportDocument): string {
    return document.document_title || (document.document_type === 'nachgutachten' ? 'Nachgutachten' : 'Erstgutachten');
}

function documentFor(id: string | null): AdminReportDocument | undefined {
    return props.reportDocuments.find((document) => document.id === id);
}

function formatEuro(value: string | null): string {
    const amount = value === null ? Number.NaN : Number.parseFloat(value);

    return Number.isFinite(amount) ? new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(amount) : '—';
}

function errorText(extraction: AdminAppraisalExtraction): string {
    return ERROR_MESSAGES[extraction.error_code ?? ''] ?? extraction.error_message ?? 'Die Auslese ist fehlgeschlagen.';
}

function submit() {
    if (!canStart.value || form.document_id === '') {
        return;
    }

    form.post(route('admin.orders.appraisal-extractions.store', props.orderId), { preserveScroll: true });
}
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex flex-wrap items-center gap-2.5">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[11px] bg-[#4FA3A6]/15 text-[#2c7a7d]">
                <MdiTextBoxSearchOutline class="size-[17px]" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Gutachten-Auslese</h2>
                <p class="mt-0.5 text-[11.5px] font-medium text-[#9bb0af]">Positionen automatisch aus dem Gutachten-PDF vorschlagen</p>
            </div>
            <span
                v-if="latest"
                class="shrink-0 rounded-full px-2.5 py-1 text-[10.5px] font-bold"
                :class="STATUS_STYLES[latest.status]"
                data-testid="extraction-status"
            >
                {{ STATUS_LABELS[latest.status] }}
            </span>
        </div>

        <div aria-live="polite" class="flex flex-col gap-3">
            <p
                v-if="!candidates.length"
                class="rounded-[13px] border border-dashed border-[#e9efee] px-4 py-6 text-center text-[12.5px] text-[#9bb0af]"
            >
                Noch kein Gutachten-PDF hinterlegt. Laden Sie das Erstgutachten hoch, um Positionen automatisch auszulesen.
            </p>

            <div v-else-if="isRunning" class="rounded-[13px] border border-[#e9efee] bg-[#f6f9f8] px-4 py-3.5" data-testid="extraction-running">
                <div class="flex items-start gap-3">
                    <span class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-[11px] bg-white text-[#2c7a7d]">
                        <span
                            class="absolute inset-0 animate-ping rounded-[11px] bg-[#4FA3A6] opacity-20 motion-reduce:hidden"
                            aria-hidden="true"
                        ></span>
                        <MdiMagnifyScan class="relative size-[17px]" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[13px] font-bold text-[#10393b]">{{ runningHeadline }}</p>
                        <p class="mt-0.5 text-[12px] text-[#6f8585]">{{ runningDescription }}</p>
                    </div>
                </div>

                <ol class="mt-3 flex flex-col gap-1.5" aria-hidden="true" data-testid="extraction-steps">
                    <li v-for="step in analysisSteps" :key="step.key" class="flex items-center gap-2.5 text-[12px]">
                        <span
                            class="flex size-[18px] shrink-0 items-center justify-center rounded-full"
                            :class="{
                                'bg-[#01b990] text-white': step.state === 'done',
                                'bg-[#4FA3A6]/15 text-[#2c7a7d]': step.state === 'active',
                                'border border-[#e9efee] bg-white text-[#cbd9d7]': step.state === 'waiting',
                            }"
                        >
                            <MdiCheck v-if="step.state === 'done'" class="size-[12px]" />
                            <MdiLoading v-else-if="step.state === 'active'" class="size-[12px] animate-spin motion-reduce:animate-none" />
                            <MdiCircleSmall v-else class="size-[12px]" />
                        </span>
                        <span :class="step.state === 'waiting' ? 'text-[#9bb0af]' : 'font-bold text-[#10393b]'">{{ step.label }}</span>
                    </li>
                </ol>

                <p class="mt-3 text-[11.5px] text-[#9bb0af]">
                    {{ latest?.status === 'pending' ? 'Eingereiht' : 'Gestartet' }}
                    {{ formatPortalDateTimeShort(latest?.started_at ?? latest?.created_at) }} · Die Karte aktualisiert sich automatisch.
                </p>
            </div>

            <Transition v-else-if="isReady && latest" name="reveal" appear>
                <div class="rounded-[13px] border border-[#01b990]/30 bg-[#01b990]/5 px-4 py-3.5" data-testid="extraction-ready">
                    <div class="flex items-start gap-2.5">
                        <MdiCheckCircle class="mt-0.5 size-[18px] shrink-0 text-[#00856a]" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <p class="text-[13px] font-bold text-[#10393b]">Analyse abgeschlossen</p>
                            <p class="mt-0.5 text-[12px] text-[#6f8585]">
                                Vorschlag aus {{ documentLabel(documentFor(latest.source_document_id) ?? candidates[0]) }} ·
                                {{ formatPortalDateTimeShort(latest.completed_at) }}
                            </p>
                        </div>
                    </div>

                    <dl class="mt-3 grid grid-cols-3 gap-2 max-[560px]:grid-cols-1">
                        <div class="rounded-[11px] bg-white px-3 py-2">
                            <dt class="text-[11px] text-[#9bb0af]">Positionen</dt>
                            <dd class="text-[15px] font-extrabold text-[#10393b] tabular-nums">{{ latest.line_count }}</dd>
                        </div>
                        <div class="rounded-[11px] bg-white px-3 py-2">
                            <dt class="text-[11px] text-[#9bb0af]">Gutachtensumme</dt>
                            <dd class="text-[15px] font-extrabold text-[#10393b] tabular-nums">{{ formatEuro(latest.total_net) }}</dd>
                        </div>
                        <div class="rounded-[11px] bg-white px-3 py-2">
                            <dt class="text-[11px] text-[#9bb0af]">Hinweise</dt>
                            <dd class="text-[15px] font-extrabold tabular-nums" :class="latest.warnings.length ? 'text-[#9A5B00]' : 'text-[#10393b]'">
                                {{ latest.warnings.length }}
                            </dd>
                        </div>
                    </dl>

                    <ul v-if="latest.warnings.length" class="mt-2.5 flex flex-col gap-1.5">
                        <li
                            v-for="(warning, index) in latest.warnings.slice(0, 3)"
                            :key="`${warning.code}-${index}`"
                            class="flex items-start gap-2 rounded-[11px] bg-[#FDF1D8] px-3 py-2 text-[12px] text-[#9A5B00]"
                        >
                            <MdiAlertCircleOutline class="mt-0.5 size-[14px] shrink-0" aria-hidden="true" />
                            <span>{{ warning.message }}</span>
                        </li>
                        <li v-if="latest.warnings.length > 3" class="pl-1 text-[11.5px] text-[#9A5B00]">
                            +{{ latest.warnings.length - 3 }} weitere Hinweise
                        </li>
                    </ul>

                    <p v-if="latest.appraisal_number || latest.appraisal_date" class="mt-2.5 text-[11.5px] text-[#9bb0af]">
                        <span v-if="latest.appraisal_number">Gutachten {{ latest.appraisal_number }}</span>
                        <span v-if="latest.appraisal_number && latest.appraisal_date"> · </span>
                        <span v-if="latest.appraisal_date">vom {{ formatPortalDate(latest.appraisal_date) }}</span>
                    </p>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-[12px] text-[#6f8585]">Die Gutachtenpositionen bleiben unverändert, bis Sie den Vorschlag übernehmen.</p>
                        <button
                            v-if="editable && !reviewOpen"
                            type="button"
                            class="flex min-h-11 shrink-0 cursor-pointer items-center gap-1.5 rounded-[13px] bg-[#10393b] px-5 text-[13px] font-bold text-white transition-all hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                            data-testid="open-review"
                            @click="reviewOpen = true"
                        >
                            <MdiClipboardCheckOutline class="size-[15px]" aria-hidden="true" />
                            Prüfen &amp; übernehmen
                        </button>
                        <p v-else-if="!editable" class="text-[12px] text-[#6f8585]">
                            Übernehmen ist nur zwischen Begutachtung und Angebotsfreigabe möglich.
                        </p>
                    </div>

                    <AdminExtractionReview v-if="reviewOpen" :extraction="latest" :report-documents="reportDocuments" @close="reviewOpen = false" />
                </div>
            </Transition>

            <div
                v-else-if="isApplied && latest"
                class="rounded-[13px] border border-[#01b990]/30 bg-[#01b990]/5 px-4 py-3.5"
                data-testid="extraction-applied"
            >
                <div class="flex items-start gap-2.5">
                    <MdiClipboardCheckOutline class="mt-0.5 size-[18px] shrink-0 text-[#00856a]" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="text-[13px] font-bold text-[#10393b]">Positionen wurden übernommen</p>
                        <p class="mt-0.5 text-[12px] text-[#6f8585]">
                            Die übernommenen Positionen stehen in der Karte „Gutachtenpositionen“ und können dort weiter bearbeitet werden.
                        </p>
                        <p class="mt-1.5 text-[11.5px] text-[#9bb0af]">
                            {{ formatPortalDateTimeShort(latest.applied_at) }}
                            <span v-if="latest.applied_by_name"> · durch {{ latest.applied_by_name }}</span>
                        </p>
                    </div>
                </div>
            </div>

            <div
                v-else-if="hasFailed && latest"
                class="rounded-[13px] border border-[#c0392b]/25 bg-[#c0392b]/5 px-4 py-3.5"
                data-testid="extraction-failed"
            >
                <div class="flex items-start gap-2.5">
                    <MdiAlertCircleOutline class="mt-0.5 size-[18px] shrink-0 text-[#c0392b]" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="text-[13px] font-bold text-[#10393b]">Analyse fehlgeschlagen</p>
                        <p class="mt-0.5 text-[12px] text-[#6f8585]">{{ errorText(latest) }}</p>

                        <ul class="mt-2.5 flex flex-col gap-1.5">
                            <li class="flex items-start gap-2 rounded-[11px] bg-white px-3 py-2 text-[12px] text-[#10393b]">
                                <MdiPencilOutline class="mt-0.5 size-[14px] shrink-0 text-[#6f8585]" aria-hidden="true" />
                                <span>Die Gutachtenpositionen können weiterhin manuell erfasst werden.</span>
                            </li>
                            <li class="flex items-start gap-2 rounded-[11px] bg-white px-3 py-2 text-[12px] text-[#10393b]">
                                <MdiRefresh class="mt-0.5 size-[14px] shrink-0 text-[#6f8585]" aria-hidden="true" />
                                <span>Sie können die Analyse mit „Erneut auslesen“ wiederholen.</span>
                            </li>
                        </ul>

                        <p class="mt-2.5 text-[11.5px] text-[#9bb0af]">
                            {{ formatPortalDateTimeShort(latest.failed_at) }} · Versuch {{ latest.attempts }}
                            <span v-if="latest.error_code"> · {{ latest.error_code }}</span>
                        </p>
                    </div>
                </div>
            </div>

            <form v-if="candidates.length" class="flex flex-col gap-2.5" @submit.prevent="submit">
                <fieldset v-if="candidates.length > 1 && canStart" class="flex flex-col gap-1.5">
                    <legend class="mb-1 text-[11px] font-bold tracking-[0.04em] text-[#9bb0af] uppercase">Quelle</legend>
                    <label
                        v-for="document in candidates"
                        :key="document.id"
                        class="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-[13px] border px-3 py-2 transition-colors motion-reduce:transition-none"
                        :class="
                            form.document_id === document.id ? 'border-[#01b990] bg-[#01b990]/5' : 'border-[#e9efee] bg-white hover:border-[#10393b]'
                        "
                    >
                        <input v-model="form.document_id" type="radio" :value="document.id" class="size-3.5 shrink-0 accent-[#01b990]" />
                        <MdiFileDocumentOutline class="size-[15px] shrink-0 text-[#9bb0af]" aria-hidden="true" />
                        <span class="min-w-0 flex-1 truncate text-[12.5px] font-bold text-[#10393b]">{{ documentLabel(document) }}</span>
                        <span class="shrink-0 text-[11.5px] text-[#9bb0af] tabular-nums">{{ formatPortalDate(document.created_at) }}</span>
                    </label>
                </fieldset>

                <InputError :message="form.errors.document_id" />

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p v-if="!editable" class="text-[12px] text-[#6f8585]">Die Auslese ist nur zwischen Begutachtung und Angebotsfreigabe möglich.</p>
                    <p v-else-if="isRunning" class="text-[12px] text-[#6f8585]">Bitte warten Sie, bis der laufende Vorgang abgeschlossen ist.</p>
                    <span v-else class="text-[12px] text-[#6f8585]">
                        {{ latest ? 'Ein neuer Lauf ersetzt den bisherigen Vorschlag nicht.' : 'Es werden keine Positionen automatisch angelegt.' }}
                    </span>

                    <button
                        type="submit"
                        :disabled="!canStart || form.processing"
                        class="flex min-h-11 shrink-0 cursor-pointer items-center gap-1.5 rounded-[13px] bg-[#10393b] px-5 text-[13px] font-bold text-white transition-all hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] disabled:cursor-default disabled:opacity-50 motion-reduce:transition-none"
                    >
                        <MdiRefresh v-if="latest && !form.processing" class="size-[15px]" aria-hidden="true" />
                        {{ form.processing ? 'Wird gestartet...' : latest ? 'Erneut auslesen' : 'Auslese starten' }}
                    </button>
                </div>
            </form>

            <div v-if="history.length" class="border-t border-[#f2f6f5] pt-2.5">
                <button
                    type="button"
                    class="flex min-h-11 w-full cursor-pointer items-center justify-between gap-2 rounded-[11px] px-1 text-left text-[12px] font-bold text-[#6f8585] transition-colors hover:text-[#10393b] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#01b990] motion-reduce:transition-none"
                    :aria-expanded="historyOpen"
                    @click="historyOpen = !historyOpen"
                >
                    Frühere Läufe ({{ history.length }})
                    <MdiChevronDown class="size-[16px] transition-transform motion-reduce:transition-none" :class="historyOpen ? 'rotate-180' : ''" />
                </button>

                <ul v-if="historyOpen" class="mt-1 flex flex-col gap-1">
                    <li
                        v-for="run in history"
                        :key="run.id"
                        class="flex flex-wrap items-center gap-2 rounded-[11px] bg-[#f6f9f8] px-3 py-2 text-[12px] text-[#6f8585]"
                    >
                        <span class="rounded-full px-2 py-0.5 text-[10.5px] font-bold" :class="STATUS_STYLES[run.status]">
                            {{ STATUS_LABELS[run.status] }}
                        </span>
                        <span class="tabular-nums">{{ formatPortalDateTimeShort(run.created_at) }}</span>
                        <span v-if="run.status === 'ready'" class="tabular-nums">{{ run.line_count }} Positionen</span>
                        <span v-else-if="run.error_code" class="min-w-0 flex-1 truncate">{{ errorText(run) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>

<style scoped>
.reveal-enter-active {
    transition:
        opacity 220ms ease-out,
        transform 220ms ease-out;
}

.reveal-enter-from {
    opacity: 0;
    transform: translateY(4px);
}

@media (prefers-reduced-motion: reduce) {
    .reveal-enter-active {
        transition: none;
    }

    .reveal-enter-from {
        opacity: 1;
        transform: none;
    }
}
</style>
