#!/usr/bin/env node
/**
 * LeasyBack V2 — Gutachten, step 3 (customer view).
 *
 * Run from the project root:
 *     node apply-step3.mjs --check     (verifies every edit, writes nothing)
 *     node apply-step3.mjs             (applies them)
 *
 * Every edit is located by a line that must exist exactly as expected. If one
 * is not found, nothing at all is written and the script says which one.
 * Originals are copied to storage/app/step3-backup/ first. Running it twice is
 * harmless: edits already in place are skipped.
 */
import fs from 'node:fs';
import path from 'node:path';

const CHECK_ONLY = process.argv.includes('--check');
const BACKUP_DIR = 'storage/app/step3-backup';
const raw = String.raw;

/* ───────────────────────────── new file ───────────────────────────── */

const NEW_FILES = {
    'resources/js/components/vehicle/AppraisalOrderDetails.vue': raw`<script setup lang="ts">
/**
 * A Gutachten order on the customer's pages (order page and vehicle page):
 * every vehicle the order covers, what was booked, the appointment, and the
 * final report once the order is completed.
 */
import { appraisalDetailRows, type AppraisalDetails, type AppraisalVehicle, type CustomerOrderCollection } from '@/lib/customerOrderFlow';
import type { OrderAttachmentData } from '@/types/order';
import { computed } from 'vue';
import MdiFileDocumentOutline from '~icons/mdi/file-document-outline';

const props = defineProps<{
    details: AppraisalDetails | null;
    collection?: CustomerOrderCollection | null;
    vehicles: AppraisalVehicle[];
    /** The final report. The server sends it only once the order is completed. */
    reports: OrderAttachmentData[];
    completed: boolean;
}>();

const rows = computed(() => (props.details ? appraisalDetailRows(props.details, props.collection ?? null) : []));

function vehicleLine(vehicle: AppraisalVehicle): string {
    return [[vehicle.make, vehicle.model].filter(Boolean).join(' '), vehicle.vin ? 'FIN ' + vehicle.vin : ''].filter(Boolean).join(' · ');
}
</script>

<template>
    <section class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
        <header class="border-b border-[#f1f5f5] px-5 py-4">
            <h2 class="text-[15px] font-bold text-[#10393b]">Fahrzeuge im Auftrag</h2>
            <p class="mt-0.5 text-[12.5px] text-[#00000080]">
                {{ vehicles.length }} {{ vehicles.length === 1 ? 'Fahrzeug' : 'Fahrzeuge' }} unter einer Auftragsnummer.
            </p>
        </header>

        <ul>
            <li
                v-for="(item, index) in vehicles"
                :key="item.vehicle_id ?? index"
                class="border-b border-[#f1f5f5] px-5 py-2.5 last:border-b-0"
            >
                <p class="text-[13px] font-semibold text-[#10393b]">{{ item.license_plate || '—' }}</p>
                <p v-if="vehicleLine(item)" class="truncate text-[11.5px] text-[#9aacac]">{{ vehicleLine(item) }}</p>
            </li>
        </ul>
    </section>

    <section v-if="rows.length" class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
        <header class="border-b border-[#f1f5f5] px-5 py-4">
            <h2 class="text-[15px] font-bold text-[#10393b]">Gutachten</h2>
        </header>

        <dl class="divide-y divide-[#f1f5f5]">
            <div v-for="row in rows" :key="row.label" class="flex items-baseline justify-between gap-4 px-5 py-2.5">
                <dt class="shrink-0 text-[12.5px] text-[#00000080]">{{ row.label }}</dt>
                <dd class="text-right text-[13px] font-semibold text-[#10393b]">{{ row.value }}</dd>
            </div>
        </dl>
    </section>

    <section class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
        <header class="border-b border-[#f1f5f5] px-5 py-4">
            <h2 class="text-[15px] font-bold text-[#10393b]">Abschlussgutachten</h2>
        </header>

        <p v-if="!reports.length" class="px-5 py-4 text-[12.5px] text-[#9aacac]">
            {{ completed ? 'Für diesen Auftrag ist kein Gutachten hinterlegt.' : 'Steht bereit, sobald der Auftrag abgeschlossen ist.' }}
        </p>

        <ul v-else>
            <li v-for="file in reports" :key="file.id" class="flex items-center gap-3 border-b border-[#f1f5f5] px-5 py-3 last:border-b-0">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-[11px] bg-[#01B990]/10 text-[#01B990]">
                    <MdiFileDocumentOutline class="text-[18px]" />
                </span>
                <span class="min-w-0 flex-1 truncate text-[13px] font-semibold text-[#10393b]">{{ file.original_name }}</span>
                <a
                    v-if="file.url"
                    :href="file.url"
                    target="_blank"
                    rel="noopener"
                    class="shrink-0 rounded-full border border-[#d8e4e3] px-3 py-1 text-[11.5px] font-semibold text-[#10393b] transition hover:border-[#01B990] hover:text-[#01B990]"
                >
                    Öffnen
                </a>
            </li>
        </ul>
    </section>
</template>
`,
};

/* ───────────────────────────── shared snippets ───────────────────────────── */

const TS_APPRAISAL_BLOCK = raw`/* ─────────────────────────── Gutachten (vehicle condition appraisal) ─────────────────────────── */

export type AppraisalStage = 'appraisal_requested' | 'appraisal_scheduled' | 'appraisal_completed';

/** REQUESTED → SCHEDULED → COMPLETED (Vehicle Condition Appraisal brief, 17 September 2026). */
export const APPRAISAL_STAGE_SEQUENCE: readonly AppraisalStage[] = ['appraisal_requested', 'appraisal_scheduled', 'appraisal_completed'];

/** One vehicle of a Gutachten order, as the server lists it under order.vehicles. */
export interface AppraisalVehicle {
    vehicle_id?: string | null;
    license_plate?: string | null;
    make?: string | null;
    model?: string | null;
    vin?: string | null;
}

/** Shape of a Gutachten order's request_payload. */
export interface AppraisalDetails {
    billing_address?: (RelocationAddress & { name?: string | null }) | null;
    cost_centre?: { name?: string | null; number?: string | null } | null;
    vehicle_location?: RelocationAddress | string | null;
    /** Pickup to a DEKRA/TÜV site. */
    pickup_requested?: boolean | null;
    /** Only ever true together with pickup_requested. */
    return_transport?: boolean | null;
    leasing_company?: string | null;
    preferred_date?: string | null;
    /** "08:00-12:00" */
    time_slot?: string | null;
    time_from?: string | null;
    time_to?: string | null;
    location_contact?: RelocationContact | null;
    notes?: string | null;
    /** Snapshot taken at booking; order.vehicles is the live list. */
    vehicles?: AppraisalVehicle[] | null;
}

const APPRAISAL_STATUS_INDEX: Record<string, number> = {
    order_requested: 0,
    order_placed: 0,
    confirmed: 1,
    completed: 2,
};

const APPRAISAL_STAGE_LABEL: Record<AppraisalStage, string> = {
    appraisal_requested: 'Gutachten angefragt',
    appraisal_scheduled: 'Gutachten terminiert',
    appraisal_completed: 'Gutachten abgeschlossen',
};

const APPRAISAL_STAGE_TOOLTIP: Record<AppraisalStage, string> = {
    appraisal_requested: 'Ihre Gutachtenanfrage ist bei Leasyback eingegangen.',
    appraisal_scheduled: 'Leasyback hat Termin und Zeitfenster für die Begutachtung bestätigt.',
    appraisal_completed: 'Die Begutachtung ist abgeschlossen, das Gutachten steht bereit.',
};

function appraisalLocation(details: AppraisalDetails): string {
    const location = details.vehicle_location;

    return typeof location === 'string' ? location.trim() : formatRelocationAddress(location);
}

function appraisalTimeWindow(details: AppraisalDetails): string {
    if (details.time_slot) {
        return details.time_slot;
    }

    return details.time_from && details.time_to ? details.time_from + '-' + details.time_to : '';
}

function appraisalConfirmedAppointment(collection: CustomerOrderCollection | null | undefined): string {
    const date = collection?.confirmed_collection_date ? formatPortalDate(collection.confirmed_collection_date) : '';

    return [date, collection?.confirmed_collection_time_slot].filter(Boolean).join(', ');
}

/**
 * The rows of the customer's "Gutachten" card. One definition, because the
 * order page, the vehicle page and the dashboard panel all show the same card.
 */
export function appraisalDetailRows(details: AppraisalDetails, collection?: CustomerOrderCollection | null): { label: string; value: string }[] {
    const contact = details.location_contact;
    const billing = details.billing_address;
    const costCentre = details.cost_centre;

    return [
        { label: 'Fahrzeugstandort', value: appraisalLocation(details) },
        { label: 'Kontakt Standort', value: [contact?.name, contact?.phone, contact?.email].filter(Boolean).join(' · ') },
        { label: 'Leasinggeber', value: details.leasing_company ?? '' },
        {
            label: 'Abholung zur Prüfstelle',
            value: details.pickup_requested == null ? '' : details.pickup_requested ? 'Ja (DEKRA/TÜV)' : 'Nein',
        },
        { label: 'Rücktransport', value: details.pickup_requested ? (details.return_transport ? 'Ja' : 'Nein') : '' },
        { label: 'Wunschtermin', value: details.preferred_date ? formatPortalDate(details.preferred_date) : '' },
        { label: 'Zeitfenster', value: appraisalTimeWindow(details) },
        { label: 'Bestätigter Termin', value: appraisalConfirmedAppointment(collection) },
        { label: 'Rechnungsadresse', value: [billing?.name, formatRelocationAddress(billing)].filter(Boolean).join(', ') },
        { label: 'Kostenstelle', value: [costCentre?.name, costCentre?.number].filter(Boolean).join(' · ') },
        { label: 'Hinweis', value: details.notes ?? '' },
    ].filter((row) => !!row.value);
}

function appraisalStageDate(stage: AppraisalStage, ctx: CustomerOrderFlowInput): string {
    switch (stage) {
        case 'appraisal_requested':
            return ctx.orderCreatedAt ?? '';
        case 'appraisal_scheduled':
            return findHistoryDate(ctx.statusHistory, new Set(['confirmed']));
        case 'appraisal_completed':
            return findHistoryDate(ctx.statusHistory, new Set(['completed']));
    }
}

function appraisalStageSubtitle(
    stage: AppraisalStage,
    details: AppraisalDetails,
    collection: CustomerOrderCollection | null | undefined,
    reached: boolean,
): string {
    switch (stage) {
        case 'appraisal_requested': {
            const date = details.preferred_date ? formatPortalDate(details.preferred_date) : '';
            const when = [date, appraisalTimeWindow(details)].filter(Boolean).join(', ');
            const count = details.vehicles?.length ?? 0;
            const note = details.notes?.trim();

            return [when ? 'Wunschtermin: ' + when : '', count > 1 ? count + ' Fahrzeuge' : '', note ? 'Hinweis: ' + note : '']
                .filter(Boolean)
                .join('\n');
        }
        case 'appraisal_scheduled': {
            const when = appraisalConfirmedAppointment(collection);
            const location = appraisalLocation(details);

            return [when ? 'Termin: ' + when : '', location ? 'Fahrzeugstandort: ' + location : ''].filter(Boolean).join('\n');
        }
        case 'appraisal_completed':
            return reached ? 'Das Gutachten steht in diesem Auftrag für Sie bereit.' : 'Nach Abschluss finden Sie das Gutachten in diesem Auftrag.';
    }
}

function getAppraisalOrderFlowSteps(ctx: CustomerOrderFlowInput): CustomerOrderFlowStep[] {
    const status = (ctx.orderStatus ?? '').trim();
    const details = ctx.appraisal ?? {};
    const collection = ctx.collection ?? null;
    const reportUrl = (ctx.appraisalReportUrl ?? '').trim();

    const step = (
        stage: AppraisalStage,
        state: { datetime: string; completed: boolean; isCurrent: boolean; isNext: boolean; isCancelled: boolean; isRejected: boolean },
    ): CustomerOrderFlowStep => {
        let label = APPRAISAL_STAGE_LABEL[stage];
        let subtitle = appraisalStageSubtitle(stage, details, collection, state.completed);
        let tooltipDescription = APPRAISAL_STAGE_TOOLTIP[stage];

        if (state.isRejected) {
            label = 'Anfrage abgelehnt';
            subtitle = 'Leasyback hat diese Gutachtenanfrage abgelehnt. Bei Fragen wenden Sie sich bitte an Ihren Ansprechpartner.';
            tooltipDescription = 'Diese Anfrage wurde abgelehnt und wird nicht weiter bearbeitet.';
        } else if (state.isCancelled) {
            label = 'Auftrag storniert';
            subtitle = 'Der Auftrag wurde bei „' + APPRAISAL_STAGE_LABEL[stage] + '" beendet.';
            tooltipDescription = 'Dieser Auftrag wurde storniert und wird nicht weiter bearbeitet.';
        }

        const built: CustomerOrderFlowStep = {
            stage,
            label,
            shortLabel: label,
            subtitle,
            tooltipDescription,
            ...state,
            isCancelled: state.isCancelled && !state.isRejected,
        };

        // The final report, linked from the last stage once it is there.
        if (stage === 'appraisal_completed' && state.completed && reportUrl) {
            built.reportDocUrl = reportUrl;
        }

        return built;
    };

    if (status === 'cancelled' || status === 'discarded') {
        const terminalEntry = ctx.statusHistory.find((entry) => entry.new_status === status);
        const priorIndex = APPRAISAL_STATUS_INDEX[(terminalEntry?.old_status ?? '').trim()] ?? 0;

        return APPRAISAL_STAGE_SEQUENCE.map((stage, index) => {
            const completed = index < priorIndex;
            const here = index === priorIndex;

            return step(stage, {
                datetime: completed ? appraisalStageDate(stage, ctx) : here ? (terminalEntry?.created_at ?? '') : '',
                completed,
                isCurrent: false,
                isNext: false,
                isCancelled: here,
                isRejected: here && status === 'discarded',
            });
        });
    }

    const progressIndex = APPRAISAL_STATUS_INDEX[status] ?? 0;
    let nextAssigned = false;

    return APPRAISAL_STAGE_SEQUENCE.map((stage, index) => {
        const isCurrent = index === progressIndex;
        const completed = index < progressIndex || (isCurrent && status === CLOSED_SUCCESSFULLY);
        const isNext = index > progressIndex && !nextAssigned;

        if (isNext) {
            nextAssigned = true;
        }

        return step(stage, {
            datetime: completed || isCurrent ? appraisalStageDate(stage, ctx) : '',
            completed,
            isCurrent,
            isNext,
            isCancelled: false,
            isRejected: false,
        });
    });
}
`;

/** Script block shared by vehicles/Show.vue and VehicleExpandedPanel.vue — both read the vehicle's current order. */
const CURRENT_ORDER_APPRAISAL_SCRIPT = raw`
/** Gutachten: one order for several vehicles; the booking form data is in request_payload. */
const isAppraisal = computed(() => currentOrder.value?.service_type === 'gutachten');

const appraisal = computed<AppraisalDetails | null>(() =>
    isAppraisal.value ? (currentOrder.value?.request_payload as unknown as AppraisalDetails | null) : null,
);

type AppraisalOrderExtras = { vehicles?: AppraisalVehicle[]; attachments?: OrderAttachmentData[] };

const appraisalOrder = computed(() => (isAppraisal.value ? (currentOrder.value as unknown as AppraisalOrderExtras | null) : null));

/** Every vehicle of the order, from the server's own list; the booking snapshot is the fallback. */
const appraisalVehicles = computed<AppraisalVehicle[]>(() => {
    const listed = appraisalOrder.value?.vehicles ?? [];

    return listed.length ? listed : (appraisal.value?.vehicles ?? []);
});

/** The final report — the server sends it only once the order is completed. */
const appraisalReports = computed(() => (appraisalOrder.value?.attachments ?? []).filter((file) => file.kind === 'final_document'));
`;

const FLOW_INPUT_LINES_CURRENT_ORDER = raw`        appraisal: appraisal.value,
        appraisalReportUrl: appraisalReports.value[0]?.url ?? null,`;

function panelCard(mobile) {
    const head = mobile ? 'px-4 pt-4' : 'px-6 pt-6';
    const pad = mobile ? 'px-4 pt-3 pb-4' : 'px-6 pt-4 pb-6';
    const row = mobile ? 'gap-3 py-3' : 'gap-4 py-4';
    const text = mobile ? 'text-[14px]' : 'text-[16px]';
    const indent = mobile ? '        ' : '                ';
    const shell = mobile
        ? raw`<div v-else-if="isAppraisal" class="flex flex-col overflow-hidden rounded-3xl border bg-white" style="border-color: #ececec">`
        : raw`<div
    v-else-if="isAppraisal"
    class="relative flex w-full flex-col overflow-hidden rounded-3xl border bg-white"
    style="border-color: #ececec"
>`;

    const body = raw`
    <div class="HEAD">
        <p class="text-[16px] font-bold uppercase" style="color: #000">GUTACHTEN</p>
    </div>

    <div class="flex flex-col gap-0 PAD">
        <p class="text-[10px] font-medium uppercase" style="color: #8f9ba7; letter-spacing: 0.5px">
            {{ appraisalVehicles.length }} {{ appraisalVehicles.length === 1 ? 'Fahrzeug' : 'Fahrzeuge' }} in diesem Auftrag
        </p>
        <ul class="pt-2 pb-3">
            <li
                v-for="(item, index) in appraisalVehicles"
                :key="item.vehicle_id ?? index"
                class="flex items-baseline justify-between gap-3 py-1"
            >
                <span class="shrink-0 text-[14px] font-bold" style="color: #2e3e3f">{{ item.license_plate }}</span>
                <span class="truncate text-right text-[13px]" style="color: #64748b">
                    {{ [item.make, item.model].filter(Boolean).join(' ') }}
                </span>
            </li>
        </ul>

        <template v-for="row in appraisalRows" :key="row.label">
            <div class="h-px bg-gray-200"></div>
            <div class="flex items-start justify-between ROW">
                <span class="shrink-0 TEXT font-normal" style="color: #64748b">{{ row.label }}</span>
                <span class="text-right TEXT font-semibold" style="color: #000">{{ row.value }}</span>
            </div>
        </template>

        <div class="h-px bg-gray-200"></div>
        <div class="pt-3">
            <p class="TEXT font-normal" style="color: #64748b">Abschlussgutachten</p>
            <p v-if="!appraisalReports.length" class="mt-1 text-[13px]" style="color: #8f9ba7">
                {{
                    currentOrder?.order_status === 'completed'
                        ? 'Für diesen Auftrag ist kein Gutachten hinterlegt.'
                        : 'Steht bereit, sobald der Auftrag abgeschlossen ist.'
                }}
            </p>
            <template v-for="file in appraisalReports" :key="file.id">
                <a
                    v-if="file.url"
                    :href="file.url"
                    target="_blank"
                    rel="noopener"
                    class="mt-2 flex items-center gap-2 text-[14px] font-semibold text-[#01b990] hover:opacity-70"
                >
                    <IconMdiFileDocumentOutline class="size-[18px] shrink-0" />
                    <span class="truncate">{{ file.original_name }}</span>
                </a>
            </template>
        </div>
    </div>
</div>
`;

    const card = (shell + body).replaceAll('HEAD', head).replaceAll('PAD', pad).replaceAll('ROW', row).replaceAll('TEXT', text);

    return (
        card
            .split('\n')
            .map((line) => (line ? indent + line : line))
            .join('\n')
    );
}

function detailsComponent(collection, completed) {
    return raw`                    <!-- Gutachten: every vehicle of the order, the booking details and the final report. -->
                    <AppraisalOrderDetails
                        v-if="isAppraisal"
                        :details="appraisal"
                        :collection="COLLECTION"
                        :vehicles="appraisalVehicles"
                        :reports="REPORTS"
                        :completed="COMPLETED"
                    />
`
        .replace('COLLECTION', collection)
        .replace('COMPLETED', completed);
}

const COMPONENT_IMPORT = raw`import AppraisalOrderDetails from '@/components/vehicle/AppraisalOrderDetails.vue';`;

/* ───────────────────────────── the edits ───────────────────────────── */

const EDITS = {
    /* 1 ─ timeline, payload shape and the shared detail rows */
    'resources/js/lib/customerOrderFlow.ts': [
        {
            replace: 'stage: CustomerOrderStage | B2bOrderStage | RelocationStage | AccidentDamageStage;',
            with: 'stage: CustomerOrderStage | B2bOrderStage | RelocationStage | AccidentDamageStage | AppraisalStage;',
        },
        {
            after: 'accident?: AccidentDamageDetails | null;',
            insert: raw`    /** gutachten: the booking form data (the order's request_payload). */
    appraisal?: AppraisalDetails | null;
    /** gutachten: link to the final report. Only there once the order is completed. */
    appraisalReportUrl?: string | null;`,
            done: 'appraisal?: AppraisalDetails | null;',
        },
        {
            before: 'export function getCustomerOrderFlowSteps(ctx: CustomerOrderFlowInput): CustomerOrderFlowStep[] | null {',
            insert: TS_APPRAISAL_BLOCK,
            done: 'function getAppraisalOrderFlowSteps(ctx: CustomerOrderFlowInput)',
        },
        {
            before: "if (ctx.channel === 'B2B') {",
            insert: raw`    if (ctx.serviceType === 'gutachten') {
        return getAppraisalOrderFlowSteps(ctx);
    }
`,
            done: 'return getAppraisalOrderFlowSteps(ctx);',
        },
    ],

    /* 2 ─ the order page */
    'resources/js/pages/orders/Show.vue': [
        { after: "import OrderProgress from '@/components/vehicle/OrderProgress.vue';", insert: COMPONENT_IMPORT },
        {
            after: 'type AccidentDamageDetails,',
            insert: '    type AppraisalDetails,\n    type AppraisalVehicle,',
            done: 'type AppraisalDetails,',
        },
        {
            after: 'const finalDocuments = computed(',
            insert: raw`
/** Gutachten: one order for several vehicles; the booking form data is in request_payload. */
const isAppraisal = computed(() => props.order.service_type === 'gutachten');

const appraisal = computed<AppraisalDetails | null>(() =>
    isAppraisal.value ? (props.order.request_payload as unknown as AppraisalDetails | null) : null,
);

/** Every vehicle of the order, from the server's own list; the booking snapshot is the fallback. */
const appraisalVehicles = computed<AppraisalVehicle[]>(() => {
    if (!isAppraisal.value) {
        return [];
    }

    const listed = (props.order as VehicleOrderData & { vehicles?: AppraisalVehicle[] }).vehicles ?? [];

    return listed.length ? listed : (appraisal.value?.vehicles ?? []);
});`,
            done: 'const isAppraisal = computed(',
        },
        {
            after: 'accident: accident.value,',
            insert: raw`        appraisal: appraisal.value,
        appraisalReportUrl: isAppraisal.value ? (finalDocuments.value[0]?.url ?? null) : null,`,
            done: 'appraisal: appraisal.value,',
        },
        {
            replace: 'if (!isB2b.value || !collection || isAccident.value) {',
            with: 'if (!isB2b.value || !collection || isAccident.value || isAppraisal.value) {',
        },
        {
            replace: '<OfferComparison :offers="order.offers"',
            with: '<OfferComparison v-if="!isAppraisal" :offers="order.offers"',
        },
        {
            after: "· {{ [vehicle.make, vehicle.model].filter(Boolean).join(' ') || 'Ohne Marke/Modell' }}",
            insert: raw`                <span v-if="appraisalVehicles.length > 1"> · eines von {{ appraisalVehicles.length }} Fahrzeugen dieses Auftrags</span>`,
            done: 'Fahrzeugen dieses Auftrags',
        },
        {
            before: '<!-- Unfallschaden: everything from the report form, plus the arranged step. -->',
            insert: detailsComponent('order.collection', "order.order_status === 'completed'").replace('REPORTS', 'finalDocuments'),
            done: '<AppraisalOrderDetails',
        },
    ],

    /* 3 ─ the vehicle page */
    'resources/js/pages/vehicles/Show.vue': [
        { after: "import OrderProgress from '@/components/vehicle/OrderProgress.vue';", insert: COMPONENT_IMPORT },
        {
            after: 'newOrderAction,',
            insert: '    type AppraisalDetails,\n    type AppraisalVehicle,',
            done: 'type AppraisalDetails,',
        },
        {
            replace: "import type { StationData } from '@/types/order';",
            with: "import type { OrderAttachmentData, StationData } from '@/types/order';",
        },
        {
            after: 'const statusColor = computed(() => STATUS_TONE[status.value.variant] ?? STATUS_TONE.secondary);',
            insert: CURRENT_ORDER_APPRAISAL_SCRIPT.replace(/\n$/, ''),
            done: 'const isAppraisal = computed(',
        },
        { after: 'relocation: relocation.value,', insert: FLOW_INPUT_LINES_CURRENT_ORDER, done: 'appraisal: appraisal.value,' },
        {
            replace: '<OfferComparison :offers="offers"',
            with: '<OfferComparison v-if="!isAppraisal" :offers="offers"',
        },
        {
            before: '<section v-if="appointment" class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">',
            insert: detailsComponent('currentOrder?.collection', "currentOrder?.order_status === 'completed'").replace('REPORTS', 'appraisalReports'),
            done: '<AppraisalOrderDetails',
        },
    ],

    /* 4 ─ the dashboard's expanded row */
    'resources/js/components/vehicle/VehicleExpandedPanel.vue': [
        {
            after: 'getCustomerOrderHeadline,',
            insert: '    appraisalDetailRows,\n    type AppraisalDetails,\n    type AppraisalVehicle,',
            done: 'type AppraisalDetails,',
        },
        {
            replace: 'import type { B2bOfferPresentationData, B2bOfferPresentationLine, OfferData }',
            with: 'import type { B2bOfferPresentationData, B2bOfferPresentationLine, OfferData, OrderAttachmentData }',
        },
        {
            after: 'const paymentModalOpen = ref(false);',
            insert:
                CURRENT_ORDER_APPRAISAL_SCRIPT +
                raw`
const appraisalRows = computed(() => (appraisal.value ? appraisalDetailRows(appraisal.value, currentOrder.value?.collection ?? null) : []));`,
            done: 'const isAppraisal = computed(',
        },
        { after: 'relocation: relocation.value,', insert: FLOW_INPUT_LINES_CURRENT_ORDER, done: 'appraisal: appraisal.value,' },
        {
            // The confirmed appointment is part of the Gutachten card; "Abholung" would mislabel it.
            replace: 'return !!collection && (!!collection.requested_collection_date',
            with: 'return !isAppraisal.value && !!collection && (!!collection.requested_collection_date',
        },
        {
            // Desktop "Angebote" card: no repair offers exist for a Gutachten.
            regex: /<div (class="flex flex-col rounded-\[16px\] border bg-white" style="border-color: #ececec">\s*<div class="flex items-center justify-between gap-3 px-6 py-6">)/,
            with: '<div v-if="!isAppraisal" $1',
            done: /<div v-if="!isAppraisal" class="flex flex-col rounded-\[16px\] border bg-white" style="border-color: #ececec">\s*<div class="flex items-center justify-between gap-3 px-6 py-6">/,
        },
        {
            // Mobile "Angebote" card (its plain wrapper <div>).
            regex: /<div>(\s*<div class="flex flex-col rounded-\[16px\] border bg-white" style="border-color: #ececec">\s*<div class="flex items-center justify-between gap-3 px-4 py-4">)/,
            with: '<div v-if="!isAppraisal">$1',
            done: /<div v-if="!isAppraisal">\s*<div class="flex flex-col rounded-\[16px\] border bg-white"/,
        },
        {
            // Desktop: the Gutachten card takes the Besichtigungsort's place.
            before: '<div v-else class="relative flex w-full flex-col rounded-[24px] border bg-white p-6" style="border-color: #ececec">',
            insert: panelCard(false),
            done: /v-else-if="isAppraisal"\s+class="relative flex w-full flex-col overflow-hidden/,
        },
        {
            // Mobile: the same.
            before: '<div v-else class="relative flex flex-col rounded-[24px] border bg-white p-6" style="border-color: #ececec">',
            insert: panelCard(true),
            done: '<div v-else-if="isAppraisal" class="flex flex-col overflow-hidden rounded-3xl border bg-white"',
        },
    ],

    /* 5 ─ the customer's order list */
    'resources/js/pages/orders/Index.vue': [
        {
            before: 'function openOrder(order: CustomerOrderRow) {',
            insert: raw`/** A Gutachten order can cover several vehicles; its row then speaks for all of them. */
type OrderListRow = CustomerOrderRow & { vehicle_count?: number };

function vehicleCount(order: OrderListRow): number {
    return order.vehicle_count ?? 1;
}

function vehicleTitle(order: OrderListRow): string {
    return vehicleCount(order) > 1 ? vehicleCount(order) + ' Fahrzeuge' : order.license_plate;
}

function vehicleSubtitle(order: OrderListRow): string {
    const others = vehicleCount(order) - 1;

    if (others > 0) {
        return order.license_plate + ' und ' + others + (others === 1 ? ' weiteres' : ' weitere');
    }

    return [order.make, order.model].filter(Boolean).join(' ') || '—';
}
`,
            done: 'function vehicleTitle(',
        },
        {
            replace: '<span class="block text-[14px] text-gray-700">{{ order.license_plate }}</span>',
            with: '<span class="block text-[14px] text-gray-700">{{ vehicleTitle(order) }}</span>',
        },
        {
            replace: '<span class="text-brand-teal block text-[15px] font-semibold">{{ order.license_plate }}</span>',
            with: '<span class="text-brand-teal block text-[15px] font-semibold">{{ vehicleTitle(order) }}</span>',
        },
        {
            replace: "{{ [order.make, order.model].filter(Boolean).join(' ') || '—' }}",
            with: '{{ vehicleSubtitle(order) }}',
            count: 2,
        },
    ],

    /* 6 ─ the final-document store also keeps a Gutachten's report */
    'app/Modules/UserProfile/Order/Services/AccidentDamageAttachmentService.php': [
        {
            replace: 'The files of an Unfallschaden (Accident Damage brief):',
            with: 'The files of an Unfallschaden (Accident Damage brief), and the final report\n * of a Gutachten (Vehicle Condition Appraisal brief), which is kept the same way:',
            done: 'of a Gutachten (Vehicle Condition Appraisal brief)',
        },
        {
            before: '/** Upload rules for the final documentation (same 20 MB limit as the form). */',
            insert: raw`    private const APPRAISAL_SERVICE_TYPE = 'gutachten';
`,
            done: 'private const APPRAISAL_SERVICE_TYPE',
        },
        {
            replace: 'if (! TransitionOrderStatus::isAccidentDamageOrder($order)) {',
            with: 'if (! TransitionOrderStatus::isAccidentDamageOrder($order) && ! self::isAppraisal($order)) {',
        },
        {
            replace: "'Abschlussdokumente gibt es nur bei einem Unfallschaden.'",
            with: "'Abschlussdokumente gibt es nur bei einem Unfallschaden oder einem Gutachten.'",
        },
        {
            replace: "$file->storeAs('accident-damage/'.$order->id.'/final',",
            with: "$file->storeAs(self::folder($order).'/'.$order->id.'/final',",
        },
        {
            replace: "'action' => 'ACCIDENT_FINAL_DOCUMENTS_ADDED',",
            with: "'action' => self::isAppraisal($order) ? 'APPRAISAL_REPORT_ADDED' : 'ACCIDENT_FINAL_DOCUMENTS_ADDED',",
        },
        {
            before: "/** Removes a final document. Customer uploads are the customer's record and stay. */",
            insert: raw`    private static function isAppraisal(LeasybackOrder $order): bool
    {
        return $order->service_type === self::APPRAISAL_SERVICE_TYPE;
    }

    /** One folder per service, so the two kinds of final document stay apart on disk. */
    private static function folder(LeasybackOrder $order): string
    {
        return self::isAppraisal($order) ? 'appraisal' : 'accident-damage';
    }
`,
            done: 'private static function isAppraisal(',
        },
        {
            after: '$file->delete();',
            insert: raw`
        $order = LeasybackOrder::find($file->order_id);`,
            done: '$order = LeasybackOrder::find($file->order_id);',
        },
        {
            replace: "'vehicle_id' => LeasybackOrder::whereKey($file->order_id)->value('vehicle_id'),",
            with: "'vehicle_id' => $order?->vehicle_id,",
        },
        {
            replace: "'action' => 'ACCIDENT_FINAL_DOCUMENT_REMOVED',",
            with: "'action' => $order !== null && self::isAppraisal($order) ? 'APPRAISAL_REPORT_REMOVED' : 'ACCIDENT_FINAL_DOCUMENT_REMOVED',",
        },
    ],

    /* 7 ─ what the customer's pages are sent */
    'app/Modules/UserProfile/Vehicle/Services/VehicleService.php': [
        {
            after: "private const ACCIDENT_DAMAGE_SERVICE_TYPE = 'unfallschaden';",
            insert: raw`
    private const APPRAISAL_SERVICE_TYPE = 'gutachten';`,
            done: 'private const APPRAISAL_SERVICE_TYPE',
        },
        {
            replace: "->orWhere('v.vin', 'like', $term);",
            with: raw`->orWhere('v.vin', 'like', $term)
                    // A multi-vehicle order is also found by any of its other vehicles.
                    ->orWhereExists(fn (Builder $linked) => $linked->selectRaw('1')
                        ->from('leasyback_order_vehicles as lov')
                        ->join('vehicles as lv', 'lv.vehicle_id', '=', 'lov.vehicle_id')
                        ->whereColumn('lov.order_id', 'o.id')
                        ->where(fn (Builder $match) => $match
                            ->where('lv.license_plate', 'like', $term)
                            ->orWhere('lv.vin', 'like', $term)));`,
            done: "->from('leasyback_order_vehicles as lov')\n                        ->join('vehicles as lv'",
        },
        {
            replace: 'return $orders->map(function (object $order) use ($collections) {',
            with: raw`// How many vehicles each order covers — more than one only for a Gutachten.
        $vehicleCounts = DB::table('leasyback_order_vehicles')
            ->whereIn('order_id', $orders->pluck('id')->all())
            ->selectRaw('order_id, COUNT(*) as total')
            ->groupBy('order_id')
            ->pluck('total', 'order_id');

        return $orders->map(function (object $order) use ($collections, $vehicleCounts) {`,
            done: 'use ($collections, $vehicleCounts) {',
        },
        {
            replace: '} elseif ($serviceType === self::ACCIDENT_DAMAGE_SERVICE_TYPE) {',
            with: raw`} elseif ($serviceType === self::APPRAISAL_SERVICE_TYPE) {
                // The confirmed appointment once operations scheduled it, the
                // requested date until then, and where the vehicles stand.
                $appointment = $collection['confirmed_collection_date'] ?? ($payload['preferred_date'] ?? null);
                $vehicleLocation = $payload['vehicle_location'] ?? null;
                $location = trim((string) (is_array($vehicleLocation) ? ($vehicleLocation['city'] ?? '') : '')) ?: null;
            } elseif ($serviceType === self::ACCIDENT_DAMAGE_SERVICE_TYPE) {`,
            done: '} elseif ($serviceType === self::APPRAISAL_SERVICE_TYPE) {',
        },
        {
            replace: "'appointment' => $appointment,",
            with: raw`'appointment' => $appointment,
                'vehicle_count' => max(1, (int) ($vehicleCounts[$order->id] ?? 1)),`,
            done: "'vehicle_count' =>",
        },
        {
            replace: "$allOrders->where('service_type', self::ACCIDENT_DAMAGE_SERVICE_TYPE)->pluck('id')->all(),",
            with: "$allOrders->whereIn('service_type', [self::ACCIDENT_DAMAGE_SERVICE_TYPE, self::APPRAISAL_SERVICE_TYPE])->pluck('id')->all(),",
        },
        {
            before: '$result = [];',
            insert: raw`        // Every vehicle of a multi-vehicle order (Gutachten), in booking order, so
        // each of its vehicles' pages can list the whole order.
        $orderVehiclesByOrder = DB::table('leasyback_order_vehicles as ov')
            ->join('vehicles as veh', 'veh.vehicle_id', '=', 'ov.vehicle_id')
            ->whereIn('ov.order_id', $orderIds)
            ->orderBy('ov.position')
            ->get(['ov.order_id', 'veh.vehicle_id', 'veh.license_plate', 'veh.make', 'veh.model', 'veh.vin'])
            ->groupBy('order_id');
`,
            done: '$orderVehiclesByOrder = DB::table(',
        },
        {
            replace: '// Unfallschaden only; an empty list for every other order.',
            with: '// Unfallschaden and Gutachten; an empty list for every other order.',
        },
        {
            replace: "'attachments' => $attachmentsByOrder[$order->id] ?? [],",
            with: raw`'attachments' => $attachmentsByOrder[$order->id] ?? [],
                    // All vehicles of the order; empty unless it is a multi-vehicle order.
                    'vehicles' => $orderVehiclesByOrder->get($order->id, collect())
                        ->map(fn ($item) => [
                            'vehicle_id' => $item->vehicle_id,
                            'license_plate' => $item->license_plate,
                            'make' => $item->make,
                            'model' => $item->model,
                            'vin' => $item->vin,
                        ])
                        ->values()
                        ->all(),`,
            done: "'vehicles' => $orderVehiclesByOrder->get(",
        },
    ],
};

/* ───────────────────────────── the engine ───────────────────────────── */

function countOf(text, needle) {
    return text.split(needle).length - 1;
}

function has(text, marker) {
    return marker instanceof RegExp ? marker.test(text) : text.includes(marker);
}

/** Returns the new text, or throws with a message naming the edit. */
function applyEdit(text, edit) {
    const marker = edit.done ?? edit.with ?? edit.insert;

    if (has(text, marker)) {
        return { text, skipped: true };
    }

    if (edit.regex) {
        const matches = text.match(new RegExp(edit.regex.source, 'g')) ?? [];

        if (matches.length !== 1) {
            throw new Error('expected 1 match, found ' + matches.length + ' for pattern ' + edit.regex);
        }

        return { text: text.replace(edit.regex, edit.with), skipped: false };
    }

    if (edit.replace !== undefined) {
        const expected = edit.count ?? 1;
        const found = countOf(text, edit.replace);

        if (found !== expected) {
            throw new Error('expected ' + expected + ' occurrence(s), found ' + found + ' of: ' + edit.replace);
        }

        return { text: text.split(edit.replace).join(edit.with), skipped: false };
    }

    const anchor = edit.after ?? edit.before;
    const lines = text.split('\n');
    const hits = lines.map((line, index) => (line.includes(anchor) ? index : -1)).filter((index) => index >= 0);

    if (hits.length !== 1) {
        throw new Error('expected 1 line, found ' + hits.length + ' containing: ' + anchor);
    }

    const at = edit.after !== undefined ? hits[0] + 1 : hits[0];
    lines.splice(at, 0, ...edit.insert.split('\n'));

    return { text: lines.join('\n'), skipped: false };
}

if (!fs.existsSync('artisan')) {
    console.error('Run this from the LeasyBack project root (the folder that contains "artisan").');
    process.exit(1);
}

const results = [];
const problems = [];

for (const [file, edits] of Object.entries(EDITS)) {
    if (!fs.existsSync(file)) {
        problems.push(file + '\n    file not found');
        continue;
    }

    const original = fs.readFileSync(file, 'utf8');
    const crlf = original.includes('\r\n');
    let text = original.replace(/\r\n/g, '\n');
    let applied = 0;
    let skipped = 0;

    edits.forEach((edit, index) => {
        try {
            const outcome = applyEdit(text, edit);
            text = outcome.text;
            outcome.skipped ? skipped++ : applied++;
        } catch (error) {
            problems.push(file + '\n    edit ' + (index + 1) + ' of ' + edits.length + ': ' + error.message);
        }
    });

    results.push({ file, original, updated: crlf ? text.replace(/\n/g, '\r\n') : text, applied, skipped });
}

if (problems.length) {
    console.error('\nNOTHING WAS CHANGED. These edits could not be placed:\n');
    problems.forEach((problem) => console.error('  ' + problem + '\n'));
    console.error('Paste this output back and the edits will be adjusted.');
    process.exit(1);
}

for (const result of results) {
    const state = result.applied === 0 ? 'already up to date' : result.applied + ' edit(s)' + (result.skipped ? ', ' + result.skipped + ' already there' : '');
    console.log((CHECK_ONLY ? 'OK       ' : result.applied ? 'UPDATED  ' : 'SKIPPED  ') + result.file + '  (' + state + ')');

    if (!CHECK_ONLY && result.applied > 0) {
        const backup = path.join(BACKUP_DIR, result.file);
        fs.mkdirSync(path.dirname(backup), { recursive: true });

        if (!fs.existsSync(backup)) {
            fs.writeFileSync(backup, result.original);
        }

        fs.writeFileSync(result.file, result.updated);
    }
}

for (const [file, content] of Object.entries(NEW_FILES)) {
    const exists = fs.existsSync(file);
    const same = exists && fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n') === content;

    console.log((CHECK_ONLY ? 'OK       ' : same ? 'SKIPPED  ' : 'CREATED  ') + file + (same ? '  (already there)' : '  (new file)'));

    if (!CHECK_ONLY && !same) {
        fs.mkdirSync(path.dirname(file), { recursive: true });
        fs.writeFileSync(file, content);
    }
}

console.log(CHECK_ONLY ? '\nCheck passed. Run again without --check to apply.' : '\nDone. Originals are in ' + BACKUP_DIR + '/');
