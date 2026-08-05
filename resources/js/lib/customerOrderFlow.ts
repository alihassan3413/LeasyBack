export type CustomerOrderStage =
    | 'requested'
    | 'appointment_confirmed'
    | 'inspection_completed'
    | 'offers_published'
    | 'offer_approved'
    | 'in_repair'
    | 'followup_completed'
    | 'vehicle_ready';

export const CUSTOMER_ORDER_STAGE_SEQUENCE: readonly CustomerOrderStage[] = [
    'requested',
    'appointment_confirmed',
    'inspection_completed',
    'offers_published',
    'offer_approved',
    'in_repair',
    'followup_completed',
    'vehicle_ready',
];

const PAYMENT_GATED_STAGE: CustomerOrderStage = 'vehicle_ready';

export const CUSTOMER_PAYMENT_FEATURE_ENABLED = false;

export type B2bOrderStage =
    | 'order_received'
    | 'collection_requested'
    | 'collection_scheduled'
    | 'vehicle_collected'
    | 'initial_appraisal'
    | 'quotations_preparing'
    | 'approval_required'
    | 'repair_approved'
    | 'workshop_commissioned'
    | 'vehicle_in_repair'
    | 'repair_completed'
    | 'final_appraisal'
    | 'vehicle_returned'
    | 'billing_completed'
    | 'order_completed';

export const B2B_ORDER_STAGE_SEQUENCE: readonly B2bOrderStage[] = [
    'order_received',
    'collection_requested',
    'collection_scheduled',
    'vehicle_collected',
    'initial_appraisal',
    'quotations_preparing',
    'approval_required',
    'repair_approved',
    'workshop_commissioned',
    'vehicle_in_repair',
    'repair_completed',
    'final_appraisal',
    'vehicle_returned',
    'billing_completed',
    'order_completed',
];

export interface CustomerOrderFlowStep {
    stage: CustomerOrderStage | B2bOrderStage;
    label: string;
    shortLabel: string;
    subtitle: string;
    tooltipDescription: string;
    datetime: string;
    completed: boolean;
    isCurrent: boolean;
    isNext: boolean;
    isCancelled: boolean;
    isRejected: boolean;
    reportDocUrl?: string;
    invoiceDocUrl?: string;
    showPaymentAction?: boolean;
}

export interface CustomerOrderStatusHistoryEntry {
    new_status: string | null;
    old_status?: string | null;
    /** Coarse actor role ('admin', 'api_key', …) — used to attribute a cancellation. */
    auth_source?: string | null;
    created_at: string;
}

export interface CustomerOrderBesichtigungsort {
    name?: string;
    strasse?: string;
    plz?: string;
    ort?: string;
    land?: string;
    termin?: string;
}

export interface CustomerOrderReportDocument {
    document_type?: string | null;
    document_title?: string | null;
    created_at?: string;
    url?: string | null;
    /** Absent in the customer payload, which already contains published documents only. */
    published?: boolean;
}

export interface CustomerOrderOffer {
    offer_status: string;
    published_at?: string | null;
    selected_at?: string | null;
    offer_sequence?: number;
    additional_notes?: string | null;
}

export interface CustomerOrderFlowInput {
    orderStatus: string | null | undefined;
    orderCreatedAt: string | null | undefined;
    statusHistory: ReadonlyArray<CustomerOrderStatusHistoryEntry>;
    besichtigungsort?: CustomerOrderBesichtigungsort | null;
    reportDocuments?: ReadonlyArray<CustomerOrderReportDocument>;
    offers?: ReadonlyArray<CustomerOrderOffer>;
    /**
     * B2B collection appointment. Absent for B2C, where the appointment
     * stages keep reading `besichtigungsort` exactly as before — the stage
     * list itself is identical either way, only the two appointment
     * labels/subtitles change when a collection is supplied.
     */
    collection?: CustomerOrderCollection | null;
    /** Resolved from the persisted vehicle/order, never from user input. Absent means B2C. */
    channel?: 'B2B' | 'B2C' | null;
}

export interface CustomerOrderCollection {
    requested_collection_date?: string | null;
    confirmed_collection_date?: string | null;
    /** Confirmed workshop repair appointment (§11) — customer-visible. */
    confirmed_repair_start_date?: string | null;
    estimated_processing_days?: number | null;
    collection_address?: {
        street?: string | null;
        number?: string | null;
        additional_address?: string | null;
        zip_code?: string | null;
        city?: string | null;
        country?: string | null;
    } | null;
    collection_note?: string | null;
}

export function formatGermanDateTime(iso: string): string {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return (
        date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) +
        ' · ' +
        date.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) +
        ' Uhr'
    );
}

function findHistoryDate(history: ReadonlyArray<CustomerOrderStatusHistoryEntry>, statuses: ReadonlySet<string>, preferredStatus?: string): string {
    if (preferredStatus) {
        const preferred = history.find((entry) => entry.new_status === preferredStatus);

        if (preferred) {
            return preferred.created_at;
        }
    }

    return history.find((entry) => entry.new_status && statuses.has(entry.new_status))?.created_at ?? '';
}

function resolveDocUrl(doc: CustomerOrderReportDocument): string {
    return doc.url?.trim() ?? '';
}

function normalizeDocKind(doc: CustomerOrderReportDocument): string {
    const type = (doc.document_type ?? '').trim().toLowerCase();

    if (type) {
        return type;
    }

    const title = (doc.document_title ?? '').toLowerCase();

    if (title.includes('nachgutachten')) return 'nachgutachten';
    if (title.includes('gutachten')) return 'gutachten';
    if (title.includes('rechnung')) return 'rechnung';

    return '';
}

function findLatestDoc(docs: ReadonlyArray<CustomerOrderReportDocument>, kind: string): CustomerOrderReportDocument | null {
    const matches = docs.filter((doc) => normalizeDocKind(doc) === kind && doc.created_at);

    if (matches.length === 0) {
        return null;
    }

    return matches.reduce((latest, doc) => (new Date(doc.created_at!).getTime() > new Date(latest.created_at!).getTime() ? doc : latest));
}

function pickRelevantOffer(offers: ReadonlyArray<CustomerOrderOffer>): CustomerOrderOffer | null {
    const byDateDesc = (a: CustomerOrderOffer, b: CustomerOrderOffer, key: 'selected_at' | 'published_at') =>
        new Date(b[key] ?? 0).getTime() - new Date(a[key] ?? 0).getTime();

    const selected = offers.filter((offer) => offer.offer_status === 'selected');

    if (selected.length > 0) {
        return [...selected].sort((a, b) => byDateDesc(a, b, 'selected_at'))[0];
    }

    const published = offers.filter((offer) => offer.offer_status === 'published');

    if (published.length > 0) {
        return [...published].sort((a, b) => byDateDesc(a, b, 'published_at'))[0];
    }

    return null;
}

function appointmentDateLabel(prefix: string, termin: string | undefined): string {
    const datePart = termin ? formatGermanDateTime(termin) : '';

    return datePart ? `${prefix} ${datePart}` : prefix;
}

function formatGermanDate(value: string): string {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function collectionDateLabel(prefix: string, date: string | null | undefined): string {
    const datePart = date ? formatGermanDate(date) : '';

    return datePart ? `${prefix} ${datePart}` : prefix;
}

function collectionAddressSubtitle(collection: CustomerOrderCollection): string {
    const address = collection.collection_address;

    if (!address) {
        return '';
    }

    return [
        [address.street, address.number].filter(Boolean).join(' '),
        [address.zip_code, address.city].filter(Boolean).join(' '),
    ]
        .filter(Boolean)
        .join('\n');
}

function appointmentDetailsSubtitle(place: CustomerOrderBesichtigungsort | null | undefined): string {
    if (!place) {
        return '';
    }

    const address = [place.strasse, `${place.plz ?? ''} ${place.ort ?? ''}`.trim()].filter(Boolean).join(', ');

    return [place.name, address].filter(Boolean).join('\n');
}

function offerApprovedSubtitle(offer: CustomerOrderOffer | null): string {
    if (!offer) {
        return 'Ihr ausgewähltes Angebot wird nun vorbereitet.';
    }

    const reference = offer.offer_sequence ? `Angebot ${String(offer.offer_sequence).padStart(2, '0')}` : 'Ihr Angebot';
    const note = offer.additional_notes?.trim();

    return note ? `${reference} – ${note}` : `${reference} wurde ausgewählt.`;
}

const STAGE_SHORT_LABEL: Record<CustomerOrderStage, string> = {
    requested: 'Wunschtermin angefragt',
    appointment_confirmed: 'Wunschtermin bestätigt',
    inspection_completed: 'Erstbegutachtung abgeschlossen',
    offers_published: 'Reparaturangebote zur Freigabe',
    offer_approved: 'Angebotsfreigabe erteilt',
    in_repair: 'In Reparaturphase',
    followup_completed: 'Nachgutachten abgeschlossen',
    vehicle_ready: 'Fahrzeug abholbereit',
};

const STAGE_TOOLTIP: Record<CustomerOrderStage, string> = {
    requested: 'Sie haben einen Wunschtermin zur Erstbegutachtung Ihres Fahrzeugs angefragt.',
    appointment_confirmed: 'Der Termin zur Erstbegutachtung wurde von der Partnerwerkstatt/dem Gutachter bestätigt.',
    inspection_completed: 'Die Erstbegutachtung wurde durchgeführt und das Gutachten steht zum Einsehen bereit.',
    offers_published: 'Ein oder mehrere Reparaturangebote liegen vor und können von Ihnen freigegeben werden.',
    offer_approved: 'Sie haben ein Reparaturangebot freigegeben. Die Reparatur wird nun vorbereitet.',
    in_repair: 'Ihr Fahrzeug befindet sich aktuell in der Reparatur bei der Partnerwerkstatt.',
    followup_completed: 'Die Nachbegutachtung nach der Reparatur wurde abgeschlossen. Gutachten und Rechnung stehen bereit.',
    vehicle_ready: 'Ihr Fahrzeug ist zur Abholung bereit, sobald die Zahlung bestätigt wurde.',
};

const B2B_STAGE_SHORT_LABEL: Record<B2bOrderStage, string> = {
    order_received: 'Auftrag eingegangen',
    collection_requested: 'Abholtermin angefragt',
    collection_scheduled: 'Abholung terminiert',
    vehicle_collected: 'Fahrzeug abgeholt',
    initial_appraisal: 'Erstgutachten verfügbar',
    quotations_preparing: 'Werkstattangebote in Vorbereitung',
    approval_required: 'Freigabe erforderlich',
    repair_approved: 'Reparatur freigegeben',
    workshop_commissioned: 'Werkstatt beauftragt',
    vehicle_in_repair: 'Fahrzeug in Reparatur',
    repair_completed: 'Reparatur abgeschlossen',
    final_appraisal: 'Nachgutachten abgeschlossen',
    vehicle_returned: 'Fahrzeug an Leasinggeber übergeben',
    billing_completed: 'Abrechnung abgeschlossen',
    order_completed: 'Auftrag abgeschlossen',
};

const B2B_STAGE_TOOLTIP: Record<B2bOrderStage, string> = {
    order_received: 'Ihre Rückgabeanfrage ist bei Leasyback eingegangen.',
    collection_requested: 'Sie haben einen Wunschtermin für die Abholung angefragt.',
    collection_scheduled: 'Leasyback hat den Abholtermin bestätigt.',
    vehicle_collected: 'Das Fahrzeug wurde bei Ihnen abgeholt.',
    initial_appraisal: 'Die Erstbegutachtung wurde durchgeführt und das Gutachten steht bereit.',
    quotations_preparing: 'Auf Basis des Gutachtens werden Werkstattangebote eingeholt.',
    approval_required: 'Ein oder mehrere Angebote liegen vor und warten auf Ihre Freigabe.',
    repair_approved: 'Sie haben ein Angebot freigegeben. Die Reparatur wird beauftragt.',
    workshop_commissioned: 'Die Werkstatt wurde mit der Reparatur beauftragt.',
    vehicle_in_repair: 'Das Fahrzeug befindet sich aktuell in der Reparatur.',
    repair_completed: 'Die Reparatur wurde abgeschlossen.',
    final_appraisal: 'Die Nachbegutachtung wurde abgeschlossen und das Nachgutachten steht bereit.',
    vehicle_returned: 'Das Fahrzeug wurde an den Leasinggeber übergeben.',
    billing_completed: 'Die Abrechnung zu diesem Auftrag wurde erstellt.',
    order_completed: 'Der Rückgabeprozess ist abgeschlossen.',
};

const B2B_STATUS_STAGE_INDEX: Record<string, number> = {
    order_requested: 1,
    order_placed: 1,
    confirmed: 2,
    vehicle_collected: 3,
    workshop_commissioned: 8,
    workshop: 9,
    repair_completed: 10,
    reinspection: 11,
    vehicle_returned: 12,
    invoice_processed: 13,
    completed: 14,
};

function resolveB2bProgressIndex(status: string, relevantOffer: CustomerOrderOffer | null): number | null {
    if (status === 'inspected') {
        if (relevantOffer?.offer_status === 'selected') return 7;
        if (relevantOffer?.offer_status === 'published') return 6;

        // A rejected offer sends the order back to the offer-preparation
        // stage: Leasyback has to source a new quotation. `pickRelevantOffer`
        // already ignores rejected offers, so this is the natural fallback.
        return 5;
    }

    return B2B_STATUS_STAGE_INDEX[status] ?? null;
}

function b2bStageDate(
    stage: B2bOrderStage,
    ctx: CustomerOrderFlowInput,
    relevantOffer: CustomerOrderOffer | null,
    gutachtenDoc: CustomerOrderReportDocument | null,
    nachgutachtenDoc: CustomerOrderReportDocument | null,
): string {
    const history = ctx.statusHistory;

    switch (stage) {
        case 'order_received':
        case 'collection_requested':
            return ctx.orderCreatedAt ?? '';
        case 'collection_scheduled':
            return findHistoryDate(history, new Set(['confirmed']));
        case 'vehicle_collected':
            return findHistoryDate(history, new Set(['vehicle_collected']));
        case 'initial_appraisal':
            return gutachtenDoc?.created_at ?? findHistoryDate(history, new Set(['inspected']));
        case 'quotations_preparing':
            return findHistoryDate(history, new Set(['inspected']));
        case 'approval_required':
            return relevantOffer?.published_at ?? '';
        case 'repair_approved':
            return relevantOffer?.selected_at ?? '';
        case 'workshop_commissioned':
            return findHistoryDate(history, new Set(['workshop_commissioned']));
        case 'vehicle_in_repair':
            return findHistoryDate(history, new Set(['workshop']));
        case 'repair_completed':
            return findHistoryDate(history, new Set(['repair_completed']));
        case 'final_appraisal':
            return nachgutachtenDoc?.created_at ?? findHistoryDate(history, new Set(['reinspection']));
        case 'vehicle_returned':
            return findHistoryDate(history, new Set(['vehicle_returned']));
        case 'billing_completed':
            return findHistoryDate(history, new Set(['invoice_processed']));
        case 'order_completed':
            return findHistoryDate(history, new Set(['completed']));
    }
}

function b2bStageSubtitle(
    stage: B2bOrderStage,
    ctx: CustomerOrderFlowInput,
    relevantOffer: CustomerOrderOffer | null,
    isCurrent: boolean,
): string {
    const collection = ctx.collection ?? null;

    switch (stage) {
        case 'collection_requested': {
            const requested = collection?.requested_collection_date;
            const note = collection?.collection_note?.trim();
            const lines = [requested ? `Wunschtermin: ${formatGermanDate(requested)}` : '', note ? `Hinweis: ${note}` : ''];

            return lines.filter(Boolean).join('\n');
        }
        case 'collection_scheduled': {
            const confirmed = collection?.confirmed_collection_date;
            const address = collection ? collectionAddressSubtitle(collection) : '';
            const lines = [confirmed ? `Bestätigter Abholtermin: ${formatGermanDate(confirmed)}` : '', address];

            return lines.filter(Boolean).join('\n');
        }
        case 'workshop_commissioned':
        case 'vehicle_in_repair': {
            // Business information, rendered as its own labelled line and kept
            // apart from the stage's status-change timestamp (§15).
            const start = collection?.confirmed_repair_start_date;
            const days = collection?.estimated_processing_days;

            return [
                start ? `Bestätigter Reparaturbeginn: ${formatGermanDate(start)}` : '',
                days != null ? `Voraussichtliche Dauer: ${days} Arbeitstage` : '',
            ]
                .filter(Boolean)
                .join('\n');
        }
        case 'approval_required':
            return isCurrent ? 'Bitte geben Sie ein Angebot Ihrer Wahl frei.' : '';
        case 'repair_approved':
            return relevantOffer ? offerApprovedSubtitle(relevantOffer) : '';
        case 'quotations_preparing': {
            if (!isCurrent) {
                return '';
            }

            const rejected = (ctx.offers ?? []).some((offer) => offer.offer_status === 'rejected');

            return rejected
                ? 'Sie haben das letzte Angebot abgelehnt. Leasyback holt ein neues Werkstattangebot ein.'
                : 'Leasyback holt auf Basis des Gutachtens Werkstattangebote ein.';
        }
        default:
            return '';
    }
}

const KNOWN_EARLY_STATUSES = new Set(['order_requested', 'order_placed']);
const REPAIR_PHASE_STATUSES = new Set(['workshop', 'reinspection', 'reworkshop', 'workshop_commissioned', 'repair_completed']);
const CLOSING_STATUSES = new Set(['vehicle_returned', 'invoice_processed']);
const TERMINAL_STATUSES = new Set(['cancelled']);

function resolveProgressIndex(status: string, relevantOffer: CustomerOrderOffer | null, hasFollowupReport: boolean): number | null {
    if (status === 'completed') return 7;
    if (CLOSING_STATUSES.has(status)) return 6;
    if (hasFollowupReport) return 6;
    if (REPAIR_PHASE_STATUSES.has(status)) return 5;
    if (relevantOffer?.offer_status === 'selected') return 4;
    if (relevantOffer?.offer_status === 'published') return 3;
    if (status === 'inspected') return 2;
    if (status === 'confirmed' || status === 'vehicle_collected') return 1;
    if (KNOWN_EARLY_STATUSES.has(status)) return 0;

    return null;
}

function getStageDate(
    stage: CustomerOrderStage,
    ctx: CustomerOrderFlowInput,
    relevantOffer: CustomerOrderOffer | null,
    gutachtenDoc: CustomerOrderReportDocument | null,
    nachgutachtenDoc: CustomerOrderReportDocument | null,
): string {
    const status = (ctx.orderStatus ?? '').trim();

    switch (stage) {
        case 'requested':
            return ctx.orderCreatedAt ?? '';
        case 'appointment_confirmed':
            return findHistoryDate(ctx.statusHistory, new Set(['confirmed']));
        case 'inspection_completed':
            return gutachtenDoc?.created_at ?? findHistoryDate(ctx.statusHistory, new Set(['inspected']));
        case 'offers_published':
            return relevantOffer?.published_at ?? '';
        case 'offer_approved':
            return relevantOffer?.selected_at ?? '';
        case 'in_repair':
            return findHistoryDate(ctx.statusHistory, REPAIR_PHASE_STATUSES, status);
        case 'followup_completed':
            return nachgutachtenDoc?.created_at ?? findHistoryDate(ctx.statusHistory, new Set(['delivered', 'completed', ...CLOSING_STATUSES]), status);
        case 'vehicle_ready':
            return '';
    }
}

/** Maps the coarse `auth_source` role onto customer-facing wording. */
function cancellationActor(authSource?: string | null): string {
    switch ((authSource ?? '').trim()) {
        case 'admin':
            return 'Leasyback';
        case 'api_key':
            return 'den Gutachter';
        default:
            return '';
    }
}

function buildStep(
    stage: CustomerOrderStage,
    ctx: CustomerOrderFlowInput,
    relevantOffer: CustomerOrderOffer | null,
    gutachtenDoc: CustomerOrderReportDocument | null,
    nachgutachtenDoc: CustomerOrderReportDocument | null,
    rechnungDoc: CustomerOrderReportDocument | null,
    state: {
        datetime: string;
        completed: boolean;
        isCurrent: boolean;
        isNext: boolean;
        isCancelled: boolean;
        isRejected: boolean;
        cancelledBy?: string | null;
    },
): CustomerOrderFlowStep {
    const termin = ctx.besichtigungsort?.termin;
    let label = STAGE_SHORT_LABEL[stage];
    let subtitle = '';

    switch (stage) {
        case 'requested':
            label = ctx.collection?.requested_collection_date
                ? collectionDateLabel('Wunschtermin Abholung', ctx.collection.requested_collection_date) + ' angefragt'
                : appointmentDateLabel('Wunschtermin', termin) + ' angefragt';
            subtitle = 'Ihr Termin zur Erstbegutachtung wird innerhalb von 72 Stunden bestätigt';
            break;
        case 'appointment_confirmed':
            if (state.isRejected) {
                label = appointmentDateLabel('Wunschtermin', termin) + ' abgelehnt';
            } else if (ctx.collection?.confirmed_collection_date) {
                label = collectionDateLabel('Abholung', ctx.collection.confirmed_collection_date) + ' bestätigt';
                subtitle = collectionAddressSubtitle(ctx.collection);
            } else {
                label = appointmentDateLabel('Wunschtermin', termin) + ' bestätigt';
                subtitle = appointmentDetailsSubtitle(ctx.besichtigungsort);
            }
            break;
        case 'inspection_completed':
            subtitle = 'Hier können Sie Ihr Gutachten einsehen';
            break;
        case 'offers_published':
            subtitle = 'Bitte geben Sie ein Angebot Ihrer Wahl innerhalb von 72 Stunden frei';
            break;
        case 'offer_approved':
            subtitle = offerApprovedSubtitle(relevantOffer);
            break;
        case 'in_repair':
            subtitle = 'Nach der Reparatur erfolgt automatisch eine Nachbegutachtung durch den Gutachter';
            break;
        case 'followup_completed':
            subtitle = 'Hier können Sie Ihr Gutachten einsehen\nRechnung einsehen und bezahlen';
            break;
        case 'vehicle_ready':
            subtitle = 'Ihr Fahrzeug kann nun abgeholt werden.\nHier können Sie Ihre Rechnung einsehen';
            break;
    }

    // A cancelled order used to keep the stage's own wording, so the only cue
    // that it had been cancelled was the red dot. Say it outright, and name
    // who did it — the customer otherwise has no way to tell a Leasyback
    // cancellation from a TÜV SÜD one.
    if (state.isCancelled) {
        const actor = cancellationActor(state.cancelledBy);

        label = `Auftrag storniert${actor ? ` durch ${actor}` : ''}`;
        subtitle = `Der Auftrag wurde bei „${STAGE_SHORT_LABEL[stage]}" beendet. Bei Fragen wenden Sie sich bitte an Ihren Ansprechpartner.`;
    }

    const step: CustomerOrderFlowStep = {
        stage,
        label,
        shortLabel: state.isCancelled ? 'Auftrag storniert' : state.isRejected ? 'Wunschtermin abgelehnt' : STAGE_SHORT_LABEL[stage],
        subtitle,
        tooltipDescription: state.isCancelled ? 'Dieser Auftrag wurde storniert und wird nicht weiter bearbeitet.' : STAGE_TOOLTIP[stage],
        datetime: state.datetime,
        completed: state.completed,
        isCurrent: state.isCurrent,
        isNext: state.isNext,
        isCancelled: state.isCancelled,
        isRejected: state.isRejected,
    };

    if (stage === 'inspection_completed' && gutachtenDoc) {
        step.reportDocUrl = resolveDocUrl(gutachtenDoc);
    }

    if (stage === 'followup_completed') {
        if (nachgutachtenDoc) step.reportDocUrl = resolveDocUrl(nachgutachtenDoc);
        if (rechnungDoc) step.invoiceDocUrl = resolveDocUrl(rechnungDoc);
        step.showPaymentAction = true;
    }

    if (stage === 'vehicle_ready' && rechnungDoc) {
        step.invoiceDocUrl = resolveDocUrl(rechnungDoc);
    }

    return step;
}

function buildB2bStep(
    stage: B2bOrderStage,
    ctx: CustomerOrderFlowInput,
    relevantOffer: CustomerOrderOffer | null,
    gutachtenDoc: CustomerOrderReportDocument | null,
    nachgutachtenDoc: CustomerOrderReportDocument | null,
    rechnungDoc: CustomerOrderReportDocument | null,
    state: {
        datetime: string;
        completed: boolean;
        isCurrent: boolean;
        isNext: boolean;
        isCancelled: boolean;
        cancelledBy?: string | null;
    },
): CustomerOrderFlowStep {
    let label = B2B_STAGE_SHORT_LABEL[stage];
    let subtitle = b2bStageSubtitle(stage, ctx, relevantOffer, state.isCurrent);

    if (state.isCancelled) {
        const actor = cancellationActor(state.cancelledBy);

        label = `Auftrag storniert${actor ? ` durch ${actor}` : ''}`;
        subtitle = `Der Auftrag wurde bei „${B2B_STAGE_SHORT_LABEL[stage]}" beendet. Bei Fragen wenden Sie sich bitte an Ihren Ansprechpartner.`;
    }

    const step: CustomerOrderFlowStep = {
        stage,
        label,
        shortLabel: state.isCancelled ? 'Auftrag storniert' : B2B_STAGE_SHORT_LABEL[stage],
        subtitle,
        tooltipDescription: state.isCancelled
            ? 'Dieser Auftrag wurde storniert und wird nicht weiter bearbeitet.'
            : B2B_STAGE_TOOLTIP[stage],
        datetime: state.datetime,
        completed: state.completed,
        isCurrent: state.isCurrent,
        isNext: state.isNext,
        isCancelled: state.isCancelled,
        isRejected: false,
    };

    if (stage === 'initial_appraisal' && gutachtenDoc) {
        step.reportDocUrl = resolveDocUrl(gutachtenDoc);
    }

    if (stage === 'final_appraisal' && nachgutachtenDoc) {
        step.reportDocUrl = resolveDocUrl(nachgutachtenDoc);
    }

    if (stage === 'billing_completed' && rechnungDoc) {
        step.invoiceDocUrl = resolveDocUrl(rechnungDoc);
    }

    return step;
}

function getB2bOrderFlowSteps(ctx: CustomerOrderFlowInput): CustomerOrderFlowStep[] | null {
    const status = (ctx.orderStatus ?? '').trim();
    const offers = ctx.offers ?? [];
    const publishedDocs = (ctx.reportDocuments ?? []).filter((doc) => doc.published !== false);
    const relevantOffer = pickRelevantOffer(offers);
    const gutachtenDoc = findLatestDoc(publishedDocs, 'gutachten');
    const nachgutachtenDoc = findLatestDoc(publishedDocs, 'nachgutachten');
    const rechnungDoc = findLatestDoc(publishedDocs, 'rechnung');

    if (TERMINAL_STATUSES.has(status)) {
        const terminalEntry = ctx.statusHistory.find((entry) => entry.new_status === status);
        const priorStatus = (terminalEntry?.old_status ?? '').trim();
        const priorIndex = Math.min(
            resolveB2bProgressIndex(priorStatus, relevantOffer) ?? 0,
            B2B_ORDER_STAGE_SEQUENCE.length - 1,
        );
        const terminalDate = terminalEntry?.created_at ?? '';
        const priorCtx: CustomerOrderFlowInput = { ...ctx, orderStatus: priorStatus };

        return B2B_ORDER_STAGE_SEQUENCE.map((stage, index) => {
            const isTerminalHere = index === priorIndex;
            const completed = index < priorIndex;

            return buildB2bStep(stage, ctx, relevantOffer, gutachtenDoc, nachgutachtenDoc, rechnungDoc, {
                datetime: completed
                    ? b2bStageDate(stage, priorCtx, relevantOffer, gutachtenDoc, nachgutachtenDoc)
                    : isTerminalHere
                      ? terminalDate
                      : '',
                completed,
                isCurrent: false,
                isNext: false,
                isCancelled: isTerminalHere,
                cancelledBy: terminalEntry?.auth_source,
            });
        });
    }

    const progressIndex = resolveB2bProgressIndex(status, relevantOffer);

    if (progressIndex === null) {
        return null;
    }

    let nextAssigned = false;

    return B2B_ORDER_STAGE_SEQUENCE.map((stage, index) => {
        const completed = index < progressIndex;
        const isCurrent = index === progressIndex;
        const isNext = index > progressIndex && !nextAssigned;

        if (isNext) {
            nextAssigned = true;
        }

        return buildB2bStep(stage, ctx, relevantOffer, gutachtenDoc, nachgutachtenDoc, rechnungDoc, {
            datetime: completed || isCurrent ? b2bStageDate(stage, ctx, relevantOffer, gutachtenDoc, nachgutachtenDoc) : '',
            completed,
            isCurrent,
            isNext,
            isCancelled: false,
        });
    });
}

export function getCustomerOrderFlowSteps(ctx: CustomerOrderFlowInput): CustomerOrderFlowStep[] | null {
    if (!ctx.orderCreatedAt) {
        return null;
    }

    if (ctx.channel === 'B2B') {
        return getB2bOrderFlowSteps(ctx);
    }

    const status = (ctx.orderStatus ?? '').trim();
    const offers = ctx.offers ?? [];
    const reportDocuments = ctx.reportDocuments ?? [];
    const relevantOffer = pickRelevantOffer(offers);
    const gutachtenDoc = findLatestDoc(reportDocuments, 'gutachten');
    const nachgutachtenDoc = findLatestDoc(reportDocuments, 'nachgutachten');
    const rechnungDoc = findLatestDoc(reportDocuments, 'rechnung');

    if (TERMINAL_STATUSES.has(status)) {
        const terminalEntry = ctx.statusHistory.find((entry) => entry.new_status === status);
        const priorStatus = (terminalEntry?.old_status ?? '').trim();
        const priorIndex = Math.min(
            resolveProgressIndex(priorStatus, relevantOffer, !!nachgutachtenDoc) ?? 0,
            CUSTOMER_ORDER_STAGE_SEQUENCE.length - 1,
        );
        const terminalDate = terminalEntry?.created_at ?? '';
        const priorCtx: CustomerOrderFlowInput = { ...ctx, orderStatus: priorStatus };

        return CUSTOMER_ORDER_STAGE_SEQUENCE.map((stage, index) => {
            const isTerminalHere = index === priorIndex;
            const completed = index < priorIndex;
            const datetime = completed
                ? getStageDate(stage, priorCtx, relevantOffer, gutachtenDoc, nachgutachtenDoc)
                : isTerminalHere
                  ? terminalDate
                  : '';

            return buildStep(stage, ctx, relevantOffer, gutachtenDoc, nachgutachtenDoc, rechnungDoc, {
                datetime,
                completed,
                isCurrent: false,
                isNext: false,
                isCancelled: isTerminalHere,
                isRejected: false,
                cancelledBy: terminalEntry?.auth_source,
            });
        });
    }

    const progressIndex = resolveProgressIndex(status, relevantOffer, !!nachgutachtenDoc);

    if (progressIndex === null) {
        return null;
    }

    let nextAssigned = false;

    return CUSTOMER_ORDER_STAGE_SEQUENCE.map((stage, index) => {
        const forcedFuture = stage === PAYMENT_GATED_STAGE;
        const completed = !forcedFuture && index < progressIndex;
        const isCurrent = !forcedFuture && index === progressIndex;
        const isUpcoming = forcedFuture || index > progressIndex;
        const isNext = isUpcoming && !nextAssigned;

        if (isNext) {
            nextAssigned = true;
        }

        const datetime = completed || isCurrent ? getStageDate(stage, ctx, relevantOffer, gutachtenDoc, nachgutachtenDoc) : '';

        return buildStep(stage, ctx, relevantOffer, gutachtenDoc, nachgutachtenDoc, rechnungDoc, {
            datetime,
            completed,
            isCurrent,
            isNext,
            isCancelled: false,
            isRejected: false,
        });
    });
}

/**
 * Statuses that leave a vehicle free for a new order — the frontend mirror of
 * VehicleService::hasUnfinishedOrder(), which the create-order endpoint
 * enforces.
 */
const FINISHED_ORDER_STATUSES = new Set(['delivered', 'completed', 'cancelled', 'discarded']);

/**
 * Whether the customer may start a new process for this vehicle.
 *
 * Must not be "has no orders at all": cancelled orders are part of the
 * customer's history now, and a vehicle whose only order was cancelled has to
 * stay startable — otherwise a cancellation permanently locks the vehicle out
 * of the flow.
 */
export function canStartNewOrder(orders: ReadonlyArray<{ order_status: string }>): boolean {
    return orders.every((order) => FINISHED_ORDER_STATUSES.has((order.order_status ?? '').trim()));
}

export function getCustomerOrderHeadline(steps: ReadonlyArray<CustomerOrderFlowStep> | null): { label: string; tooltipDescription: string } | null {
    if (!steps) {
        return null;
    }

    const headline = steps.find((step) => step.isCurrent || step.isCancelled || step.isRejected);

    if (!headline) {
        return null;
    }

    return { label: headline.shortLabel, tooltipDescription: headline.tooltipDescription };
}
