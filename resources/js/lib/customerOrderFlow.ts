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

export interface CustomerOrderFlowStep {
    stage: CustomerOrderStage;
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

function findHistoryDate(
    history: ReadonlyArray<CustomerOrderStatusHistoryEntry>,
    statuses: ReadonlySet<string>,
    preferredStatus?: string,
): string {
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

const KNOWN_EARLY_STATUSES = new Set(['order_requested', 'order_placed']);
const REPAIR_PHASE_STATUSES = new Set(['workshop', 'reinspection', 'reworkshop']);
const TERMINAL_STATUSES = new Set(['cancelled']);

function resolveProgressIndex(status: string, relevantOffer: CustomerOrderOffer | null, hasFollowupReport: boolean): number | null {
    if (hasFollowupReport) return 6;
    if (REPAIR_PHASE_STATUSES.has(status)) return 5;
    if (relevantOffer?.offer_status === 'selected') return 4;
    if (relevantOffer?.offer_status === 'published') return 3;
    if (status === 'inspected') return 2;
    if (status === 'confirmed') return 1;
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
            return nachgutachtenDoc?.created_at ?? findHistoryDate(ctx.statusHistory, new Set(['delivered', 'completed']), status);
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
            label = appointmentDateLabel('Wunschtermin', termin) + ' angefragt';
            subtitle = 'Ihr Termin zur Erstbegutachtung wird innerhalb von 72 Stunden bestätigt';
            break;
        case 'appointment_confirmed':
            if (state.isRejected) {
                label = appointmentDateLabel('Wunschtermin', termin) + ' abgelehnt';
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
        tooltipDescription: state.isCancelled
            ? 'Dieser Auftrag wurde storniert und wird nicht weiter bearbeitet.'
            : STAGE_TOOLTIP[stage],
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

export function getCustomerOrderFlowSteps(ctx: CustomerOrderFlowInput): CustomerOrderFlowStep[] | null {
    if (!ctx.orderCreatedAt) {
        return null;
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

export function getCustomerOrderHeadline(
    steps: ReadonlyArray<CustomerOrderFlowStep> | null,
): { label: string; tooltipDescription: string } | null {
    if (!steps) {
        return null;
    }

    const headline = steps.find((step) => step.isCurrent || step.isCancelled || step.isRejected);

    if (!headline) {
        return null;
    }

    return { label: headline.shortLabel, tooltipDescription: headline.tooltipDescription };
}
