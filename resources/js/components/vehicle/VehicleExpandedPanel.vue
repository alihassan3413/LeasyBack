<script setup lang="ts">
import OrderStatusTimeline from '@/components/shared/OrderStatusTimeline.vue';
import { TableCell, TableRow } from '@/components/ui/table';
import AddVehicleModal from '@/components/vehicle/AddVehicleModal.vue';
import UploadDocumentModal from '@/components/vehicle/UploadDocumentModal.vue';
import { CUSTOMER_PAYMENT_FEATURE_ENABLED, formatGermanDateTime, getCustomerOrderFlowSteps, getCustomerOrderHeadline } from '@/lib/customerOrderFlow';
import { toOrderTimelineEntries, type OrderTimelineEntry } from '@/lib/timeline';
import { getOrderStatusLabel } from '@/lib/vehicleStatus';
import type { OfferData } from '@/types/order';
import type { VehicleData } from '@/types/vehicle';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface PanelDocument {
    id: string;
    documentType: string;
    title: string;
    url: string | null;
    isReport: boolean;
}

interface PanelOffer {
    id: string;
    offerId: string;
    name: string;
    cost: number;
    note: string;
    accepted: boolean;
    status: string;
}

const props = withDefaults(
    defineProps<{
        vehicle: VehicleData;
        /**
         * Renders the panel for an Admin surface (the Admin vehicle list's
         * expanded row and the detail page's Kundenansicht) rather than for
         * the vehicle's owner.
         *
         * Admin gets the v1 offer actions — publish, withdraw, and accepting
         * on the customer's behalf. Accepting routes to
         * admin.orders.offers.select, *not* the customer's offers.select:
         * OfferPolicy::select() still refuses admins (the BOLA fix documented
         * there) and OfferController::select() renders that as a 404, so the
         * two paths stay separate abilities with separate audit actions.
         */
        admin?: boolean;
    }>(),
    { admin: false },
);

const editVehicleOpen = ref(false);
const uploadDocsOpen = ref(false);

const DOCUMENT_TYPE_LABELS: Record<string, string> = {
    leasingvertrag: 'Leasingvertrag',
    vorschaden: 'Vorschaden',
    gutachten: 'Gutachten',
    nachgutachten: 'Nachgutachten',
    rechnung: 'Rechnung',
    tuv: 'TÜV',
};

const NON_DELETABLE_DOCUMENT_TYPES = new Set(['gutachten', 'nachgutachten', 'rechnung', 'report', 'invoice']);

function documentTypeLabel(type?: string): string {
    const key = (type ?? '').trim();

    if (!key) {
        return 'Sonstige Dokumente';
    }

    const mapped = DOCUMENT_TYPE_LABELS[key.toLowerCase()];

    if (mapped) {
        return mapped;
    }

    return key.charAt(0).toUpperCase() + key.slice(1);
}

const documents = computed<PanelDocument[]>(() => {
    const all: PanelDocument[] = props.vehicle.documents.map((doc) => ({
        id: doc.document_id,
        documentType: doc.document_type ?? '',
        title: doc.original_file_name ?? '',
        url: null,
        isReport: false,
    }));

    for (const order of props.vehicle.orders) {
        for (const doc of order.report_documents) {
            all.push({
                id: doc.id,
                documentType: doc.document_type ?? '',
                title: doc.document_title ?? '',
                url: doc.url,
                isReport: true,
            });
        }
    }

    return all;
});

function getDocumentTypeKey(doc: PanelDocument): string {
    const title = `${doc.title} ${doc.documentType}`.toLowerCase();

    for (const type of Object.keys(DOCUMENT_TYPE_LABELS)) {
        if (title.includes(type) || title.includes(DOCUMENT_TYPE_LABELS[type].toLowerCase())) {
            return type;
        }
    }

    return doc.documentType.trim().toLowerCase() || '__other__';
}

function isReportLike(doc: PanelDocument): boolean {
    const type = doc.documentType.trim().toLowerCase();

    if (type === 'gutachten' || type === 'nachgutachten' || type === 'report') {
        return true;
    }

    const title = doc.title.toLowerCase();

    return title.includes('gutachten') || title.includes('nachgutachten') || title.includes('report');
}

function canDeleteDocument(doc: PanelDocument): boolean {
    if (doc.isReport) {
        return false;
    }

    if (NON_DELETABLE_DOCUMENT_TYPES.has(doc.documentType.trim().toLowerCase())) {
        return false;
    }

    const title = doc.title.toLowerCase();

    return !(
        title.includes('gutachten') ||
        title.includes('nachgutachten') ||
        title.includes('rechnung') ||
        title.includes('report') ||
        title.includes('invoice')
    );
}

function getDocumentDisplayText(doc: PanelDocument): string {
    if (doc.documentType) {
        return documentTypeLabel(doc.documentType);
    }

    return 'Dokument';
}

const groupedDocuments = computed(() => {
    const groups: { key: string; title: string; items: PanelDocument[] }[] = [];
    const indexByKey = new Map<string, number>();

    for (const doc of documents.value) {
        if (isReportLike(doc)) {
            continue;
        }

        const key = getDocumentTypeKey(doc);
        let index = indexByKey.get(key);

        if (index === undefined) {
            index = groups.length;
            indexByKey.set(key, index);
            groups.push({ key, title: documentTypeLabel(key), items: [] });
        }

        groups[index].items.push(doc);
    }

    return groups;
});

const firstOrder = computed(() => props.vehicle.orders[0] ?? null);

const besichtigungsort = computed(() => firstOrder.value?.request_payload?.besichtigungsort ?? null);

const terminFormatted = computed(() => {
    const termin = besichtigungsort.value?.termin;

    return termin ? formatGermanDateTime(termin) : '';
});

const allReportDocuments = computed(() => props.vehicle.orders.flatMap((order) => order.report_documents));

const rawOffers = computed<OfferData[]>(() => firstOrder.value?.offers ?? []);

const customerFlowSteps = computed(() => {
    const order = firstOrder.value;

    if (!order) {
        return null;
    }

    return getCustomerOrderFlowSteps({
        orderStatus: order.order_status,
        orderCreatedAt: order.created_at,
        statusHistory: order.status_updates,
        besichtigungsort: order.request_payload?.besichtigungsort,
        reportDocuments: allReportDocuments.value,
        offers: rawOffers.value,
    });
});

const customerHeadline = computed(() => getCustomerOrderHeadline(customerFlowSteps.value));

const timelineHeaderLabel = computed(() => {
    if (!firstOrder.value) {
        return 'STATUS: KEINE AUFTRÄGE';
    }

    const headline = customerHeadline.value;

    return `STATUS: ${(headline?.label ?? getOrderStatusLabel(firstOrder.value.order_status)).toUpperCase()}`;
});

const timelineHeaderTooltipDescription = computed(() => customerHeadline.value?.tooltipDescription);

const timelineEntries = computed<OrderTimelineEntry[]>(() => {
    if (!firstOrder.value) {
        return [{ datetime: '', label: 'Keine Aufträge vorhanden', completed: false }];
    }

    return toOrderTimelineEntries(customerFlowSteps.value, firstOrder.value.order_status);
});

const offersData = computed<PanelOffer[]>(() =>
    rawOffers.value.map((offer) => ({
        id: offer.offer_sequence.toString().padStart(2, '0'),
        offerId: offer.offer_id,
        name: `Angebot ${offer.offer_sequence}`,
        cost: Number(offer.final_total_gross ?? 0),
        note: offer.additional_notes ?? '',
        accepted: offer.offer_status === 'selected',
        status: offer.offer_status,
    })),
);

const hasRealOffers = computed(() => offersData.value.length > 0);
const acceptedOffer = computed(() => offersData.value.find((offer) => offer.accepted));

const pendingOfferId = ref<string | null>(null);
const selectingOfferId = ref<string | null>(null);

/**
 * Both roles funnel through the same confirm dialog; only the endpoint and
 * the wording differ. An admin may accept only a *published* offer — the same
 * rule OfferService::selectOffer() enforces for the customer.
 */
function requestSelect(offerId: string) {
    if (acceptedOffer.value) {
        return;
    }

    const offer = offersData.value.find((candidate) => candidate.offerId === offerId);

    if (props.admin && offer?.status !== 'published') {
        return;
    }

    pendingOfferId.value = offerId;
}

function canSelect(offer: PanelOffer): boolean {
    if (acceptedOffer.value || selectingOfferId.value === offer.offerId) {
        return false;
    }

    return props.admin ? offer.status === 'published' : true;
}

function selectTitle(offer: PanelOffer): string {
    if (!props.admin) {
        return 'Angebot auswählen';
    }

    return offer.status === 'published' ? 'Im Auftrag des Kunden annehmen' : 'Nur veröffentlichte Angebote können angenommen werden';
}

const OFFER_STATUS_LABELS: Record<string, string> = {
    draft: 'Entwurf',
    published: 'Veröffentlicht',
    selected: 'Angenommen',
    closed: 'Geschlossen',
    cancelled: 'Storniert',
};

const OFFER_STATUS_PILLS: Record<string, string> = {
    draft: 'background: #f4f7f6; color: #6f8585',
    published: 'background: rgba(1, 185, 144, 0.12); color: #00856a',
    selected: 'background: rgba(239, 132, 80, 0.14); color: #c0622e',
    closed: 'background: #f4f7f6; color: #9bb0af',
    cancelled: 'background: rgba(220, 38, 38, 0.1); color: #991b1b',
};

function offerStatusLabel(status: string): string {
    return OFFER_STATUS_LABELS[status] ?? status;
}

function offerStatusPill(status: string): string {
    return OFFER_STATUS_PILLS[status] ?? OFFER_STATUS_PILLS.draft;
}

/**
 * The one offer an admin may accept from the footer button. Only unambiguous
 * when exactly one offer is published — with several, the admin picks a
 * specific one from its row instead.
 */
const acceptableOnBehalf = computed(() => {
    if (!props.admin || acceptedOffer.value) {
        return null;
    }

    const published = offersData.value.filter((offer) => offer.status === 'published');

    return published.length === 1 ? published[0] : null;
});

const onBehalfHint = computed(() => {
    if (acceptedOffer.value) {
        return 'Für diesen Auftrag wurde bereits ein Angebot angenommen';
    }

    if (acceptableOnBehalf.value) {
        return 'Das veröffentlichte Angebot im Auftrag des Kunden annehmen';
    }

    return offersData.value.some((offer) => offer.status === 'published')
        ? 'Mehrere Angebote veröffentlicht — bitte oben eines auswählen'
        : 'Kein veröffentlichtes Angebot vorhanden';
});

/** Admin-side offer actions — the endpoints AdminOffersCard uses, same policy. */
const mutatingOfferId = ref<string | null>(null);

function publishOffer(offerId: string) {
    mutatingOfferId.value = offerId;

    router.patch(route('admin.orders.offers.publish', offerId), {}, { preserveScroll: true, onFinish: () => (mutatingOfferId.value = null) });
}

function withdrawOffer(offerId: string) {
    mutatingOfferId.value = offerId;

    router.patch(route('admin.orders.offers.cancel', offerId), {}, { preserveScroll: true, onFinish: () => (mutatingOfferId.value = null) });
}

function cancelSelect() {
    pendingOfferId.value = null;
}

function confirmSelect() {
    const offerId = pendingOfferId.value;

    if (!offerId) {
        return;
    }

    selectingOfferId.value = offerId;

    const options = {
        preserveScroll: true,
        onFinish: () => {
            selectingOfferId.value = null;
            pendingOfferId.value = null;
        },
    };

    if (props.admin) {
        router.patch(route('admin.orders.offers.select', offerId), {}, options);

        return;
    }

    router.post(route('offers.select', offerId), {}, options);
}

function deleteDocument(doc: PanelDocument) {
    router.delete(route('vehicles.documents.destroy', [props.vehicle.vehicle_id, doc.id]), { preserveScroll: true });
}

function formatDate(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('de-DE');
}
</script>

<template>
    <TableRow class="hidden border-0 hover:bg-transparent md:table-row">
        <TableCell colspan="12" class="max-w-0 overflow-x-auto p-0 whitespace-normal">
            <div class="columns-1 gap-4 bg-[#EFEFEF] p-4 *:mb-4 *:break-inside-avoid md:columns-2 2xl:columns-3">
                <div class="flex w-full flex-col overflow-hidden rounded-3xl border bg-white" style="border-color: #ececec">
                    <OrderStatusTimeline
                        :entries="timelineEntries"
                        :header-label="timelineHeaderLabel"
                        :header-tooltip-description="timelineHeaderTooltipDescription"
                    >
                        <template #actions="{ entry }">
                            <template v-if="entry.docUrl">
                                <a
                                    :href="entry.docUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-[#01b990] hover:opacity-70"
                                    title="Gutachten herunterladen"
                                >
                                    <IconMaterialSymbolsDownload class="size-[18.5px] shrink-0" />
                                </a>
                                <a
                                    :href="entry.docUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-[#01b990] hover:opacity-70"
                                    title="Gutachten öffnen"
                                >
                                    <IconMdiOpenInNew class="size-[18.5px] shrink-0" />
                                </a>
                            </template>
                            <a
                                v-if="entry.invoiceUrl"
                                :href="entry.invoiceUrl"
                                target="_blank"
                                rel="noopener"
                                class="text-[#01b990] hover:opacity-70"
                                title="Rechnung ansehen"
                            >
                                <IconMdiReceiptTextOutline class="size-[18.5px] shrink-0" />
                            </a>
                            <button
                                v-if="entry.showPaymentAction"
                                type="button"
                                :disabled="!CUSTOMER_PAYMENT_FEATURE_ENABLED"
                                class="text-[#01b990] hover:opacity-70 disabled:cursor-not-allowed disabled:opacity-30"
                                title="Bezahlen (bald verfügbar)"
                            >
                                <IconMdiCreditCardOutline class="size-[18.5px] shrink-0" />
                            </button>
                        </template>
                    </OrderStatusTimeline>
                </div>

                <div class="flex w-full flex-col gap-4">
                    <div class="relative flex flex-col rounded-[16px] border bg-white" style="border-color: #ececec">
                        <button class="absolute top-5 right-5 transition-opacity hover:opacity-60" @click="uploadDocsOpen = true">
                            <IconMdiFileUploadOutline class="size-[18.5px] shrink-0" style="color: #01b990" />
                        </button>
                        <div class="p-6">
                            <p class="text-[16px] font-bold uppercase" style="color: #2e3e3f">Fahrzeugdokumente</p>
                            <div class="mt-2 h-px bg-gray-200"></div>
                        </div>

                        <div class="flex flex-col gap-5 p-6 pt-0">
                            <div v-for="group in groupedDocuments" :key="group.key" class="flex flex-col gap-3">
                                <div>
                                    <p class="text-[16px] font-semibold text-[#000000] uppercase">
                                        {{ group.title }}
                                    </p>
                                    <div class="mt-2 h-px bg-gray-200"></div>
                                </div>
                                <div v-for="doc in group.items" :key="doc.id" class="flex items-center justify-between gap-3">
                                    <span class="flex-1 truncate text-[14px] font-normal text-[#475569]" :title="getDocumentDisplayText(doc)">
                                        {{ getDocumentDisplayText(doc) }}
                                    </span>
                                    <div class="flex items-center gap-2">
                                        <a v-if="doc.url" :href="doc.url" target="_blank" class="flex-shrink-0 text-[#01b990] hover:opacity-70">
                                            <IconMaterialSymbolsDownload class="size-[18.5px] shrink-0" />
                                        </a>
                                        <button
                                            v-if="canDeleteDocument(doc)"
                                            class="flex-shrink-0 text-[#EF4444] hover:opacity-70"
                                            @click="deleteDocument(doc)"
                                        >
                                            <IconMdiDeleteOutline class="size-[18.5px] shrink-0" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div v-if="documents.length === 0" class="text-[14px] text-[#b7c2c2]">Keine Dokumente gefunden</div>
                        </div>
                    </div>
                </div>

                <div class="relative w-full">
                    <div
                        class="flex flex-col rounded-[16px] border bg-white"
                        :style="hasRealOffers ? 'border-color: #ececec' : 'border-color: #ececec; opacity: 0.5'"
                    >
                        <div class="px-6 py-6">
                            <p class="text-[16px] font-bold uppercase" style="color: #2e3e3f">Angebote</p>
                        </div>

                        <div class="flex flex-col gap-5 px-6">
                            <div v-for="offer in offersData" :key="offer.id" class="flex flex-col gap-2">
                                <div
                                    class="flex items-center gap-4 rounded-[50px] border px-4 py-2"
                                    :style="
                                        offer.accepted
                                            ? 'border-color: #EF8450; background: rgba(239, 132, 80, 0.08)'
                                            : 'border-color: #ECECEC; background: white'
                                    "
                                >
                                    <button
                                        type="button"
                                        :disabled="!canSelect(offer)"
                                        class="mt-1 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full border-2 disabled:cursor-default"
                                        :style="
                                            offer.accepted ? 'border-color: #EF8450; background: #EF8450' : 'border-color: #B7C2C2; background: white'
                                        "
                                        :title="selectTitle(offer)"
                                        @click.stop="requestSelect(offer.offerId)"
                                    >
                                        <div v-if="offer.accepted" class="h-4.5 w-4.5 rounded-full bg-white"></div>
                                    </button>

                                    <div class="flex min-w-0 flex-1 flex-col gap-1 overflow-hidden py-1">
                                        <div class="flex items-center justify-between gap-3">
                                            <p
                                                class="min-w-0 flex-1 truncate text-[14px] font-bold"
                                                style="color: #2e3e3f"
                                                :title="`${offer.id} - ${offer.name}`"
                                            >
                                                {{ offer.id }} - {{ offer.name }}
                                            </p>
                                            <p
                                                class="flex-shrink-0 text-[16px] font-semibold"
                                                :style="offer.accepted ? 'color: #EF8450' : 'color: #2e3e3f'"
                                            >
                                                {{ offer.cost.toLocaleString('de-DE') }} €
                                            </p>
                                        </div>
                                        <p
                                            class="line-clamp-2 text-[12px] leading-snug"
                                            :class="{ 'cursor-help': offer.note && offer.note.trim() }"
                                            :title="offer.note && offer.note.trim() ? offer.note.trim() : undefined"
                                            style="color: #8f9ba7"
                                        >
                                            {{ (offer.note && offer.note.trim()) || 'Weitere Informationen zum Angebot folgen.' }}
                                        </p>
                                    </div>
                                </div>

                                <!--
                                    Admin-only row, deliberately outside the pill so the
                                    customer card's shape and rhythm are untouched. Indented
                                    to the pill's text column (16px padding + 24px radio + 16px gap).
                                -->
                                <div v-if="admin" class="flex items-center justify-between gap-3 pr-4 pl-14">
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10.5px] font-bold"
                                        :style="offerStatusPill(offer.status)"
                                    >
                                        <span class="h-[4px] w-[4px] rounded-full bg-current"></span>
                                        {{ offerStatusLabel(offer.status) }}
                                    </span>

                                    <div v-if="offer.status === 'draft' || offer.status === 'published'" class="flex items-center gap-1.5">
                                        <button
                                            v-if="offer.status === 'draft'"
                                            type="button"
                                            class="rounded-full border border-[#01b990] px-3 py-1 text-[11px] font-bold text-[#01b990] transition-all hover:bg-[#01b990] hover:text-white disabled:opacity-40"
                                            :disabled="mutatingOfferId === offer.offerId"
                                            @click.stop="publishOffer(offer.offerId)"
                                        >
                                            Veröffentlichen
                                        </button>

                                        <button
                                            type="button"
                                            class="rounded-full border border-[#ECECEC] px-3 py-1 text-[11px] font-bold text-[#EF4444] transition-all hover:border-[#EF4444] hover:bg-[#EF4444] hover:text-white disabled:opacity-40"
                                            :disabled="mutatingOfferId === offer.offerId"
                                            @click.stop="withdrawOffer(offer.offerId)"
                                        >
                                            {{ offer.status === 'draft' ? 'Verwerfen' : 'Zurückziehen' }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 px-6 pb-6">
                            <button
                                v-if="admin"
                                type="button"
                                class="w-full rounded-[50px] py-4 text-[12px] font-semibold tracking-wide uppercase transition-all disabled:cursor-not-allowed"
                                :style="acceptableOnBehalf ? 'background: #01B990; color: #ffffff' : 'background: #e0e0e0; color: #9e9e9e'"
                                :disabled="!acceptableOnBehalf"
                                :title="onBehalfHint"
                                @click.stop="acceptableOnBehalf && requestSelect(acceptableOnBehalf.offerId)"
                            >
                                Im Auftrag des Kunden annehmen
                            </button>

                            <button
                                v-else
                                class="w-full rounded-[50px] py-4 text-[12px] font-semibold tracking-wide uppercase"
                                style="background: #e0e0e0; color: #9e9e9e"
                            >
                                Angebot annehmen
                            </button>
                        </div>

                        <div v-if="acceptedOffer" class="px-6 pt-5 pb-6">
                            <div class="flex items-center justify-between gap-3 rounded-[50px] px-7 py-2.5" style="background: #ef8450">
                                <span class="min-w-0 flex-1 text-[13px] leading-snug font-normal text-white">
                                    Angenommenes Angebot: {{ acceptedOffer.id }} {{ acceptedOffer.name }}
                                </span>
                                <span class="shrink-0 text-[15px] font-normal whitespace-nowrap text-white">
                                    {{ acceptedOffer.cost.toLocaleString('de-DE') }} €
                                </span>
                            </div>
                        </div>
                    </div>
                    <div v-if="!hasRealOffers" class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center">
                        <div class="rounded-full bg-white/80 px-6 py-3 shadow-lg">
                            <p class="text-[18px] font-bold" style="color: #ef8450">Keine Angebote</p>
                        </div>
                    </div>
                </div>

                <div class="relative flex w-full flex-col rounded-[24px] border bg-white p-6" style="border-color: #ececec">
                    <div class="pb-6">
                        <p class="text-[16px] font-bold uppercase" style="color: #2e3e3f">Besichtigungsort</p>
                    </div>

                    <template v-if="besichtigungsort">
                        <div class="flex items-center gap-5 pb-6">
                            <div
                                class="flex size-[56px] shrink-0 items-center justify-center rounded-full"
                                style="background-color: rgba(1, 185, 144, 0.1)"
                            >
                                <IconMdiOfficeBuildingOutline class="size-7" style="color: #01b990" />
                            </div>
                            <p class="min-w-0 flex-1 text-[18px] font-bold wrap-break-word" style="color: #2e3e3f">
                                {{ besichtigungsort.name }}
                            </p>
                        </div>

                        <div class="pb-5">
                            <p class="text-[10px] font-medium uppercase" style="color: #8f9ba7; letter-spacing: 0.5px">Termin</p>
                            <div class="flex items-center gap-3 pt-2">
                                <IconMdiCalendarClockOutline class="size-[18px] shrink-0" style="color: #5a6b7a" />
                                <p class="text-[14px] font-bold" style="color: #2e3e3f">
                                    {{ terminFormatted || 'Kein Termin' }}
                                </p>
                            </div>
                        </div>

                        <div class="mb-5 h-px bg-gray-200"></div>

                        <div class="flex items-start gap-4">
                            <IconMdiMapMarkerOutline class="mt-0.5 size-[18px] shrink-0" style="color: #5a6b7a" />
                            <span class="text-[14px] leading-relaxed font-normal" style="color: #2e3e3f">
                                {{ besichtigungsort.strasse }}<br />
                                {{ besichtigungsort.plz }} {{ besichtigungsort.ort }}
                                <template v-if="besichtigungsort.land"> ({{ besichtigungsort.land.toUpperCase() }}) </template>
                            </span>
                        </div>
                    </template>
                    <div v-else class="text-[14px] font-normal" style="color: #b7c2c2">Kein Besichtigungsort verfügbar</div>
                </div>

                <div class="relative flex w-full flex-col overflow-hidden rounded-3xl border bg-white" style="border-color: #ececec">
                    <button class="absolute top-6 right-6 transition-opacity hover:opacity-60" @click="editVehicleOpen = true">
                        <IconMdiPencil class="size-5 shrink-0" style="color: #01b990" />
                    </button>
                    <div class="px-6 pt-6">
                        <p class="text-[16px] font-bold uppercase" style="color: #000">FAHRZEUGDATEN</p>
                    </div>

                    <div class="flex flex-col gap-0 px-6 pt-4 pb-6">
                        <div class="flex items-center justify-between py-4">
                            <span class="text-[16px] font-normal" style="color: #64748b">Kennzeichen</span>
                            <span class="text-[16px] font-semibold" style="color: #000">{{ vehicle.license_plate }}</span>
                        </div>
                        <div class="h-px bg-gray-200"></div>
                        <div class="flex items-center justify-between py-4">
                            <span class="text-[16px] font-normal" style="color: #64748b">Modell</span>
                            <span class="text-[16px] font-semibold" style="color: #000">{{ vehicle.make }} {{ vehicle.model }}</span>
                        </div>
                        <div class="h-px bg-gray-200"></div>
                        <div class="flex items-center justify-between py-4">
                            <span class="text-[16px] font-normal" style="color: #64748b">Leasinggeber</span>
                            <span class="text-[16px] font-semibold" style="color: #000">{{ vehicle.leasinggeber || 'Nicht verfügbar' }}</span>
                        </div>
                        <div class="h-px bg-gray-200"></div>
                        <div class="flex items-center justify-between py-4">
                            <span class="text-[16px] font-normal" style="color: #64748b">Rückgabetermin</span>
                            <span class="text-[16px] font-semibold" style="color: #000">{{ formatDate(vehicle.leasing_end_date) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </TableCell>
    </TableRow>

    <div class="flex flex-col gap-4 bg-[#EFEFEF] p-4 md:hidden">
        <div class="flex flex-col overflow-hidden rounded-3xl border bg-white" style="border-color: #ececec">
            <OrderStatusTimeline
                :entries="timelineEntries"
                :header-label="timelineHeaderLabel"
                :header-tooltip-description="timelineHeaderTooltipDescription"
                header-class="px-4 py-4 flex items-center justify-between"
                body-class="flex-1 px-4 pb-4"
                :transform-provider-label="false"
            >
                <template #actions="{ entry }">
                    <template v-if="entry.docUrl">
                        <a
                            :href="entry.docUrl"
                            target="_blank"
                            rel="noopener"
                            class="text-[#01b990] hover:opacity-70"
                            title="Gutachten herunterladen"
                        >
                            <IconMaterialSymbolsDownload class="size-[18.5px] shrink-0" />
                        </a>
                        <a :href="entry.docUrl" target="_blank" rel="noopener" class="text-[#01b990] hover:opacity-70" title="Gutachten öffnen">
                            <IconMdiOpenInNew class="size-[18.5px] shrink-0" />
                        </a>
                    </template>
                    <a
                        v-if="entry.invoiceUrl"
                        :href="entry.invoiceUrl"
                        target="_blank"
                        rel="noopener"
                        class="text-[#01b990] hover:opacity-70"
                        title="Rechnung ansehen"
                    >
                        <IconMdiReceiptTextOutline class="size-[18.5px] shrink-0" />
                    </a>
                    <button
                        v-if="entry.showPaymentAction"
                        type="button"
                        :disabled="!CUSTOMER_PAYMENT_FEATURE_ENABLED"
                        class="text-[#01b990] hover:opacity-70 disabled:cursor-not-allowed disabled:opacity-30"
                        title="Bezahlen (bald verfügbar)"
                    >
                        <IconMdiCreditCardOutline class="size-[18.5px] shrink-0" />
                    </button>
                </template>
            </OrderStatusTimeline>
        </div>

        <div class="relative flex flex-col rounded-[16px] border bg-white" style="border-color: #ececec">
            <button class="absolute top-4 right-4 transition-opacity hover:opacity-60" @click="uploadDocsOpen = true">
                <IconMdiFileUploadOutline class="size-[18.5px] shrink-0" style="color: #01b990" />
            </button>
            <div class="p-4">
                <p class="text-[16px] font-bold uppercase" style="color: #2e3e3f">Fahrzeugdokumente</p>
                <div class="mt-2 h-px bg-gray-200"></div>
            </div>

            <div class="flex flex-col gap-4 p-4 pt-0">
                <div v-for="group in groupedDocuments" :key="group.key" class="flex flex-col gap-3">
                    <div>
                        <p class="text-[16px] font-semibold text-[#000000] uppercase">
                            {{ group.title }}
                        </p>
                        <div class="mt-2 h-px bg-gray-200"></div>
                    </div>
                    <div v-for="doc in group.items" :key="doc.id" class="flex items-center justify-between gap-3">
                        <span class="flex-1 truncate text-[14px] font-normal text-[#475569]" :title="getDocumentDisplayText(doc)">
                            {{ getDocumentDisplayText(doc) }}
                        </span>
                        <div class="flex items-center gap-2">
                            <a v-if="doc.url" :href="doc.url" target="_blank" class="flex-shrink-0 text-[#01b990] hover:opacity-70">
                                <IconMaterialSymbolsDownload class="size-[18.5px] shrink-0" />
                            </a>
                            <button v-if="canDeleteDocument(doc)" class="flex-shrink-0 text-[#EF4444] hover:opacity-70" @click="deleteDocument(doc)">
                                <IconMdiDeleteOutline class="size-[18.5px] shrink-0" />
                            </button>
                        </div>
                    </div>
                </div>
                <div v-if="documents.length === 0" class="text-[14px] text-[#b7c2c2]">Keine Dokumente gefunden</div>
            </div>
        </div>

        <div class="relative">
            <div
                class="flex flex-col rounded-[16px] border bg-white"
                :style="hasRealOffers ? 'border-color: #ececec' : 'border-color: #ececec; opacity: 0.5'"
            >
                <div class="px-4 py-4">
                    <p class="text-[16px] font-bold uppercase" style="color: #2e3e3f">Angebote</p>
                </div>

                <div class="flex flex-col gap-3 px-4">
                    <div v-for="offer in offersData" :key="offer.id" class="flex flex-col gap-2">
                        <div
                            class="flex items-center gap-3 rounded-[20px] border px-3 py-3"
                            :style="
                                offer.accepted
                                    ? 'border-color: #EF8450; background: rgba(239, 132, 80, 0.08)'
                                    : 'border-color: #ECECEC; background: white'
                            "
                        >
                            <button
                                type="button"
                                :disabled="!canSelect(offer)"
                                class="mt-1 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full border-2 disabled:cursor-default"
                                :style="offer.accepted ? 'border-color: #EF8450; background: #EF8450' : 'border-color: #B7C2C2; background: white'"
                                :title="selectTitle(offer)"
                                @click.stop="requestSelect(offer.offerId)"
                            >
                                <div v-if="offer.accepted" class="h-3.5 w-3.5 rounded-full bg-white"></div>
                            </button>

                            <div class="flex min-w-0 flex-1 flex-col gap-1 overflow-hidden">
                                <div class="flex items-center justify-between gap-2">
                                    <p
                                        class="min-w-0 flex-1 truncate text-[13px] font-bold"
                                        style="color: #2e3e3f"
                                        :title="`${offer.id} - ${offer.name}`"
                                    >
                                        {{ offer.id }} - {{ offer.name }}
                                    </p>
                                    <p class="flex-shrink-0 text-[14px] font-semibold" :style="offer.accepted ? 'color: #EF8450' : 'color: #2e3e3f'">
                                        {{ offer.cost.toLocaleString('de-DE') }} €
                                    </p>
                                </div>
                                <p
                                    class="line-clamp-2 text-[11px] leading-snug"
                                    :class="{ 'cursor-help': offer.note && offer.note.trim() }"
                                    :title="offer.note && offer.note.trim() ? offer.note.trim() : undefined"
                                    style="color: #8f9ba7"
                                >
                                    {{ (offer.note && offer.note.trim()) || 'Weitere Informationen zum Angebot folgen.' }}
                                </p>
                            </div>
                        </div>

                        <div v-if="admin" class="flex flex-wrap items-center justify-between gap-2 pr-1 pl-8">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10.5px] font-bold"
                                :style="offerStatusPill(offer.status)"
                            >
                                <span class="h-[4px] w-[4px] rounded-full bg-current"></span>
                                {{ offerStatusLabel(offer.status) }}
                            </span>

                            <div v-if="offer.status === 'draft' || offer.status === 'published'" class="flex items-center gap-1.5">
                                <button
                                    v-if="offer.status === 'draft'"
                                    type="button"
                                    class="rounded-full border border-[#01b990] px-3 py-1 text-[11px] font-bold text-[#01b990] disabled:opacity-40"
                                    :disabled="mutatingOfferId === offer.offerId"
                                    @click.stop="publishOffer(offer.offerId)"
                                >
                                    Veröffentlichen
                                </button>

                                <button
                                    type="button"
                                    class="rounded-full border border-[#ECECEC] px-3 py-1 text-[11px] font-bold text-[#EF4444] disabled:opacity-40"
                                    :disabled="mutatingOfferId === offer.offerId"
                                    @click.stop="withdrawOffer(offer.offerId)"
                                >
                                    {{ offer.status === 'draft' ? 'Verwerfen' : 'Zurückziehen' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="admin && !acceptedOffer" class="px-4 pt-4">
                    <button
                        type="button"
                        class="w-full rounded-[50px] py-3 text-[12px] font-semibold tracking-wide uppercase disabled:cursor-not-allowed"
                        :style="acceptableOnBehalf ? 'background: #01B990; color: #ffffff' : 'background: #e0e0e0; color: #9e9e9e'"
                        :disabled="!acceptableOnBehalf"
                        :title="onBehalfHint"
                        @click.stop="acceptableOnBehalf && requestSelect(acceptableOnBehalf.offerId)"
                    >
                        Im Auftrag des Kunden annehmen
                    </button>
                </div>

                <div v-if="acceptedOffer" class="px-4 pt-4 pb-4">
                    <div class="flex items-center justify-between rounded-[20px] px-4 py-3" style="background: #ef8450">
                        <span class="min-w-0 flex-1 text-[12px] leading-snug font-normal text-white">
                            Angenommenes Angebot: {{ acceptedOffer.id }} {{ acceptedOffer.name }}
                        </span>
                        <span class="flex-shrink-0 text-[14px] font-normal whitespace-nowrap text-white">
                            {{ acceptedOffer.cost.toLocaleString('de-DE') }} €
                        </span>
                    </div>
                </div>
            </div>
            <div v-if="!hasRealOffers" class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center">
                <div class="rounded-full bg-white/80 px-4 py-2 shadow-lg">
                    <p class="text-[16px] font-bold" style="color: #ef8450">Keine Angebote</p>
                </div>
            </div>
        </div>

        <div class="relative flex flex-col rounded-[24px] border bg-white p-6" style="border-color: #ececec">
            <div class="pb-4">
                <p class="text-[16px] font-bold uppercase" style="color: #2e3e3f">Besichtigungsort</p>
            </div>

            <template v-if="besichtigungsort">
                <div class="flex items-center gap-4 pb-4">
                    <div class="flex size-[48px] shrink-0 items-center justify-center rounded-full" style="background-color: rgba(1, 185, 144, 0.1)">
                        <IconMdiOfficeBuildingOutline class="size-6" style="color: #01b990" />
                    </div>
                    <p class="min-w-0 flex-1 text-[16px] font-bold wrap-break-word" style="color: #2e3e3f">
                        {{ besichtigungsort.name }}
                    </p>
                </div>

                <div class="pb-4">
                    <p class="text-[10px] font-medium uppercase" style="color: #8f9ba7; letter-spacing: 0.5px">Termin</p>
                    <div class="flex items-center gap-3 pt-2">
                        <IconMdiCalendarClockOutline class="size-[16px] shrink-0" style="color: #5a6b7a" />
                        <p class="text-[13px] font-bold" style="color: #2e3e3f">
                            {{ terminFormatted || 'Kein Termin' }}
                        </p>
                    </div>
                </div>

                <div class="mb-4 h-px bg-gray-200"></div>

                <div class="flex items-start gap-3">
                    <IconMdiMapMarkerOutline class="mt-0.5 size-[16px] shrink-0" style="color: #5a6b7a" />
                    <span class="text-[13px] leading-relaxed font-normal" style="color: #2e3e3f">
                        {{ besichtigungsort.strasse }}<br />
                        {{ besichtigungsort.plz }} {{ besichtigungsort.ort }}
                        <template v-if="besichtigungsort.land"> ({{ besichtigungsort.land.toUpperCase() }}) </template>
                    </span>
                </div>
            </template>
            <div v-else class="text-[13px] font-normal" style="color: #b7c2c2">Kein Besichtigungsort verfügbar</div>
        </div>

        <div class="relative flex flex-col overflow-hidden rounded-3xl border bg-white" style="border-color: #ececec">
            <button class="absolute top-4 right-4 transition-opacity hover:opacity-60" @click="editVehicleOpen = true">
                <IconMdiPencil class="size-4 shrink-0" style="color: #01b990" />
            </button>
            <div class="px-4 pt-4">
                <p class="text-[16px] font-bold uppercase" style="color: #000">FAHRZEUGDATEN</p>
            </div>

            <div class="flex flex-col gap-0 px-4 pt-3 pb-4">
                <div class="flex items-center justify-between py-3">
                    <span class="text-[14px] font-normal" style="color: #64748b">Kennzeichen</span>
                    <span class="text-[14px] font-semibold" style="color: #000">{{ vehicle.license_plate }}</span>
                </div>
                <div class="h-px bg-gray-200"></div>
                <div class="flex items-center justify-between py-3">
                    <span class="text-[14px] font-normal" style="color: #64748b">Modell</span>
                    <span class="text-[14px] font-semibold" style="color: #000">{{ vehicle.make }} {{ vehicle.model }}</span>
                </div>
                <div class="h-px bg-gray-200"></div>
                <div class="flex items-center justify-between py-3">
                    <span class="text-[14px] font-normal" style="color: #64748b">Leasinggeber</span>
                    <span class="text-[14px] font-semibold" style="color: #000">{{ vehicle.leasinggeber || 'Nicht verfügbar' }}</span>
                </div>
                <div class="h-px bg-gray-200"></div>
                <div class="flex items-center justify-between py-3">
                    <span class="text-[14px] font-normal" style="color: #64748b">Rückgabetermin</span>
                    <span class="text-[14px] font-semibold" style="color: #000">{{ formatDate(vehicle.leasing_end_date) }}</span>
                </div>
            </div>
        </div>
    </div>

    <AddVehicleModal v-model:open="editVehicleOpen" :vehicle="vehicle" />
    <UploadDocumentModal v-model:open="uploadDocsOpen" :vehicle-id="vehicle.vehicle_id" :documents="vehicle.documents" />

    <div v-if="pendingOfferId" class="fixed inset-0 z-50 flex items-center justify-center bg-black/5 p-4" @click="cancelSelect">
        <div class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" @click.stop>
            <h3 class="text-[18px] font-bold text-[#2e3e3f]">
                {{ admin ? 'Im Auftrag des Kunden annehmen' : 'Angebot auswählen' }}
            </h3>
            <p class="mt-3 text-[14px] leading-relaxed text-[#5a6b7a]">
                {{
                    admin
                        ? 'Dieses Angebot wird verbindlich im Auftrag des Kunden angenommen. Alle übrigen Angebote werden geschlossen und die Auswahl kann danach nicht mehr geändert werden.'
                        : 'Sind Sie sicher, dass Sie dieses Angebot auswählen möchten? Sie können die Auswahl danach nicht mehr ändern.'
                }}
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <button
                    :disabled="selectingOfferId !== null"
                    class="rounded-full border border-gray-300 px-5 py-2.5 text-[14px] font-medium text-[#2e3e3f] hover:bg-gray-50 disabled:opacity-50"
                    @click="cancelSelect"
                >
                    Abbrechen
                </button>
                <button
                    :disabled="selectingOfferId !== null"
                    class="rounded-full px-5 py-2.5 text-[14px] font-semibold text-white disabled:opacity-50"
                    style="background: #ef8450"
                    @click="confirmSelect"
                >
                    {{ selectingOfferId !== null ? 'Wird ausgewählt...' : 'Bestätigen' }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
