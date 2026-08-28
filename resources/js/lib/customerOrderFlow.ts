import { formatPortalDate, formatPortalDateTime } from '@/lib/portalDate';

export type CustomerOrderStage =
    | 'requested'
    | 'appointment_confirmed'
    | 'inspection_completed'
    | 'offers_published'
    | 'offer_approved'
    | 'workshop_commissioned'
    | 'in_repair'
    | 'followup_completed'
    | 'awaiting_payment'
    | 'vehicle_ready'
    | 'case_closed';

export const CUSTOMER_ORDER_STAGE_SEQUENCE: readonly CustomerOrderStage[] = [
    'requested',
    'appointment_confirmed',
    'inspection_completed',
    'offers_published',
    'offer_approved',
    'workshop_commissioned',
    'in_repair',
    'followup_completed',
    // Derived, not a persisted status. `delivered` is reached before the money
    // arrives because reaching it is what triggers the charge, so this rung is
    // what stands between "repairs done" and "come and collect it".
    'awaiting_payment',
    'vehicle_ready',
    'case_closed',
];

/**
 * The stage the server derived from the order status and the repair charge.
 * Mirrors App\Support\RepairPaymentPresentation — the rule lives there, and
 * this file only decides the wording each audience sees for it.
 */
export type RepairPaymentStage = 'none' | 'awaiting_payment' | 'payment_processing' | 'payment_settled' | 'payment_not_required';

export interface CustomerOrderRepairPayment {
    stage: RepairPaymentStage;
    status?: string | null;
    amount_cents?: number | null;
    /** True only when the viewer can act on it — already false for Admin. */
    payable?: boolean;
}

export function repairPaymentStage(ctx: CustomerOrderFlowInput): RepairPaymentStage {
    return ctx.repairPayment?.stage ?? 'none';
}

/** Whether the vehicle is still held. The customer's banner asks the same question. */
export function repairPaymentBlocksPickup(ctx: CustomerOrderFlowInput): boolean {
    const stage = repairPaymentStage(ctx);

    return stage === 'awaiting_payment' || stage === 'payment_processing';
}

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
    /** The server-derived repair-payment stage. Absent means there is nothing to present. */
    repairPayment?: CustomerOrderRepairPayment | null;
    /**
     * Who is reading. Both see the same derived stage — only the wording
     * differs, because "please pay" and "awaiting payment" are the same fact
     * addressed to different people.
     */
    audience?: 'customer' | 'admin';
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

/**
 * Kept as a re-export rather than a second implementation: every surface that
 * shows an order date has to agree on the zone, and two copies of the
 * formatting is how they stopped agreeing in the first place.
 */
export { formatPortalDateTime as formatGermanDateTime } from '@/lib/portalDate';

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
    const datePart = termin ? formatPortalDateTime(termin) : '';

    return datePart ? `${prefix} ${datePart}` : prefix;
}

function collectionDateLabel(prefix: string, date: string | null | undefined): string {
    const datePart = date ? formatPortalDate(date) : '';

    return datePart ? `${prefix} ${datePart}` : prefix;
}

function collectionAddressSubtitle(collection: CustomerOrderCollection): string {
    const address = collection.collection_address;

    if (!address) {
        return '';
    }

    return [[address.street, address.number].filter(Boolean).join(' '), [address.zip_code, address.city].filter(Boolean).join(' ')]
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

/**
 * The agreed repair dates, once there are any. Shared by the commissioning and
 * repair stages: the same two facts answer "when does it start" before the car
 * goes in and "how long is it in there" afterwards.
 */
function repairScheduleSubtitle(collection: CustomerOrderCollection | null | undefined): string {
    const start = collection?.confirmed_repair_start_date;
    const days = collection?.estimated_processing_days;

    return [start ? `Reparaturbeginn: ${formatPortalDate(start)}` : '', days != null ? `Voraussichtliche Dauer: ${days} Arbeitstage` : '']
        .filter(Boolean)
        .join('\n');
}

/**
 * Whether the customer has turned an offer down. Both channels need it and for
 * the same reason: a rejection leaves no live offer, so the timeline falls back
 * to the stage before the offer existed and would otherwise read as though
 * nothing had happened.
 */
function hasRejectedOffer(ctx: CustomerOrderFlowInput): boolean {
    return (ctx.offers ?? []).some((offer) => offer.offer_status === 'rejected');
}

/**
 * Whether a stage actually happened, for the two rungs the order status alone
 * cannot vouch for.
 *
 * The timeline derives one index from the status and then marks every earlier
 * rung as complete, which is sound for stages the status itself proves — an
 * order cannot be `workshop` without having been confirmed and inspected. It is
 * not sound for the offer rungs. `inspected → workshop` is a deliberate,
 * supported jump for repairs agreed outside the system, and taking it used to
 * tick "Reparaturangebote zur Freigabe" and "Angebotsfreigabe erteilt" on an
 * order that never had an offer at all — the second one even claiming "Ihr
 * ausgewähltes Angebot wird nun vorbereitet." to a customer who had chosen
 * nothing. Those two rungs are therefore proved by the offers themselves.
 */
function stageHappened(stage: CustomerOrderStage, ctx: CustomerOrderFlowInput): boolean {
    const offers = ctx.offers ?? [];

    switch (stage) {
        case 'offers_published':
            return offers.length > 0;
        case 'offer_approved':
            return offers.some((offer) => offer.offer_status === 'selected');
        // A rung the order only *took* if money actually moved. A repair that
        // came to 0,00 €, or an order that predates payments entirely, passed
        // it without it ever happening — which is what `skipped` means, and is
        // honest in a way a green tick reading "Zahlung erhalten" would not be.
        case 'awaiting_payment':
            return repairPaymentStage(ctx) === 'payment_settled';
        default:
            return true;
    }
}

function offerApprovedSubtitle(offer: CustomerOrderOffer | null): string {
    if (!offer) {
        return '';
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
    workshop_commissioned: 'Werkstatt beauftragt',
    in_repair: 'In Reparaturphase',
    followup_completed: 'Nachgutachten abgeschlossen',
    awaiting_payment: 'Reparatur abgeschlossen – Zahlung erforderlich',
    vehicle_ready: 'Fahrzeug abholbereit',
    case_closed: 'Vorgang abgeschlossen',
};

/**
 * The payment rung's wording, by derived stage and by audience.
 *
 * Kept as one table rather than two so the pair for each stage is visible
 * side by side: they must always describe the same fact, and the failure mode
 * this whole change exists to fix is exactly two surfaces drifting apart.
 */
const PAYMENT_STAGE_LABEL: Record<RepairPaymentStage, { customer: string; admin: string }> = {
    none: { customer: 'Zahlung', admin: 'Zahlung' },
    awaiting_payment: {
        customer: 'Reparatur abgeschlossen – Zahlung erforderlich',
        admin: 'Reparatur abgeschlossen – Zahlung ausstehend',
    },
    payment_processing: {
        customer: 'Zahlung wird verarbeitet',
        admin: 'Reparatur abgeschlossen – Zahlung wird verarbeitet',
    },
    payment_settled: { customer: 'Zahlung erhalten', admin: 'Zahlung erhalten' },
    payment_not_required: { customer: 'Keine Zahlung erforderlich', admin: 'Keine Zahlung erforderlich' },
};

const PAYMENT_STAGE_TOOLTIP: Record<RepairPaymentStage, { customer: string; admin: string }> = {
    none: {
        customer: 'Nach Abschluss der Reparatur werden die Reparaturkosten fällig.',
        admin: 'Nach Abschluss der Reparatur wird die Reparaturzahlung fällig.',
    },
    awaiting_payment: {
        customer: 'Die Reparatur ist abgeschlossen. Ihr Fahrzeug kann erst nach Zahlungseingang abgeholt werden.',
        admin: 'Die Reparatur ist abgeschlossen, die Zahlung steht noch aus. Übergabe und Abschluss sind bis zum Zahlungseingang gesperrt.',
    },
    payment_processing: {
        customer: 'Ihre Zahlung wird derzeit verarbeitet. Sobald sie bestätigt ist, kann Ihr Fahrzeug abgeholt werden.',
        admin: 'Die Zahlung wird bei Stripe verarbeitet. Übergabe und Abschluss bleiben bis zur Bestätigung gesperrt.',
    },
    payment_settled: {
        customer: 'Ihre Zahlung ist eingegangen. Ihr Fahrzeug kann abgeholt werden.',
        admin: 'Die Reparaturzahlung ist eingegangen. Die Übergabe kann bestätigt werden.',
    },
    payment_not_required: {
        customer: 'Für diese Reparatur fallen keine Kosten an.',
        admin: 'Für diese Reparatur fallen keine Kosten an — die Übergabe ist nicht gesperrt.',
    },
};

function formatEuroAmount(cents: number): string {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(cents / 100);
}

/**
 * The payment rung's second line. Both audiences are told the amount and that
 * the vehicle is held — the difference is only whose move it is.
 */
function paymentStageSubtitle(stage: RepairPaymentStage, audience: 'customer' | 'admin', amountCents: number | null): string {
    const amount = amountCents && amountCents > 0 ? formatEuroAmount(amountCents) : '';

    if (stage === 'payment_settled') {
        return amount ? `${amount} erhalten` : 'Die Zahlung ist eingegangen';
    }

    if (stage === 'payment_not_required') {
        return 'Für diese Reparatur fallen keine Kosten an';
    }

    const held =
        audience === 'admin'
            ? 'Übergabe und Abschluss sind bis zum Zahlungseingang gesperrt'
            : 'Ihr Fahrzeug kann erst nach Zahlungseingang abgeholt werden';

    if (stage === 'payment_processing') {
        const processing = audience === 'admin' ? 'Zahlung wird verarbeitet' : 'Ihre Zahlung wird derzeit verarbeitet';

        return amount ? `${amount} – ${processing}.\n${held}` : `${processing}.\n${held}`;
    }

    return amount ? `Offener Betrag: ${amount}.\n${held}` : held;
}

const STAGE_TOOLTIP: Record<CustomerOrderStage, string> = {
    requested: 'Sie haben einen Wunschtermin zur Erstbegutachtung Ihres Fahrzeugs angefragt.',
    appointment_confirmed: 'Der Termin zur Erstbegutachtung wurde von der Partnerwerkstatt/dem Gutachter bestätigt.',
    inspection_completed: 'Die Erstbegutachtung wurde durchgeführt und das Gutachten steht zum Einsehen bereit.',
    offers_published: 'Ein oder mehrere Reparaturangebote liegen vor und können von Ihnen freigegeben werden.',
    offer_approved: 'Sie haben ein Reparaturangebot freigegeben. Die Reparatur wird nun vorbereitet.',
    workshop_commissioned: 'Die Werkstatt aus Ihrem freigegebenen Angebot wurde beauftragt und stimmt den Reparaturtermin ab.',
    in_repair: 'Ihr Fahrzeug befindet sich aktuell in der Reparatur bei der Partnerwerkstatt.',
    followup_completed: 'Die Nachbegutachtung nach der Reparatur wurde abgeschlossen. Gutachten und Rechnung stehen bereit.',
    awaiting_payment: 'Die Reparatur ist abgeschlossen. Ihr Fahrzeug kann erst nach Zahlungseingang abgeholt werden.',
    vehicle_ready: 'Ihr Fahrzeug ist fertig und steht bei der Werkstatt zur Abholung bereit.',
    case_closed: 'Sie haben Ihr Fahrzeug abgeholt. Der Vorgang ist abgeschlossen — es steht nichts mehr aus.',
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

function b2bStageSubtitle(stage: B2bOrderStage, ctx: CustomerOrderFlowInput, relevantOffer: CustomerOrderOffer | null, isCurrent: boolean): string {
    const collection = ctx.collection ?? null;

    switch (stage) {
        case 'collection_requested': {
            const requested = collection?.requested_collection_date;
            const note = collection?.collection_note?.trim();
            const lines = [requested ? `Wunschtermin: ${formatPortalDate(requested)}` : '', note ? `Hinweis: ${note}` : ''];

            return lines.filter(Boolean).join('\n');
        }
        case 'collection_scheduled': {
            const confirmed = collection?.confirmed_collection_date;
            const address = collection ? collectionAddressSubtitle(collection) : '';
            const lines = [confirmed ? `Bestätigter Abholtermin: ${formatPortalDate(confirmed)}` : '', address];

            return lines.filter(Boolean).join('\n');
        }
        case 'workshop_commissioned':
        case 'vehicle_in_repair': {
            // Business information, rendered as its own labelled line and kept
            // apart from the stage's status-change timestamp (§15).
            const start = collection?.confirmed_repair_start_date;
            const days = collection?.estimated_processing_days;

            return [
                start ? `Bestätigter Reparaturbeginn: ${formatPortalDate(start)}` : '',
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

            return hasRejectedOffer(ctx)
                ? 'Sie haben das letzte Angebot abgelehnt. Leasyback holt ein neues Werkstattangebot ein.'
                : 'Leasyback holt auf Basis des Gutachtens Werkstattangebote ein.';
        }
        default:
            return '';
    }
}

const KNOWN_EARLY_STATUSES = new Set(['order_requested', 'order_placed']);
const REPAIR_PHASE_STATUSES = new Set(['workshop', 'reinspection', 'reworkshop', 'repair_completed']);

/**
 * Instructed, but the car is not in the workshop's hands yet. Its own rung
 * rather than part of the repair phase: between "you approved the repair" and
 * "your car is being repaired" there is a real wait, and collapsing it left the
 * customer looking at a stage that had not started.
 */
const COMMISSIONED_STATUS = 'workshop_commissioned';
const CLOSING_STATUSES = new Set(['vehicle_returned', 'invoice_processed']);
const TERMINAL_STATUSES = new Set(['cancelled']);

/**
 * The successful terminal, as against TERMINAL_STATUSES' unsuccessful one.
 *
 * Both flows mark the rung an order stands on as `isCurrent` and every rung
 * behind it as `completed`, which is right while there is work in progress and
 * wrong at the end: the closing stage is the rung the order *finished* on, and
 * marking it current alone left a finished case drawn with an unfinished last
 * step. It stays `isCurrent` as well — the page header and the Admin status
 * header both read their headline from it.
 *
 * Only one surface ever showed the bug. toOrderTimelineEntries() folds
 * `isCurrent` into `completed` for its own rendering, so the dashboard panel
 * and Admin looked correct; OrderProgress.vue reads `step.completed` straight
 * from here, which is why the B2C vehicle page — and only it — kept the last
 * step grey after an order was completed.
 */
const CLOSED_SUCCESSFULLY = 'completed';

function resolveProgressIndex(
    status: string,
    relevantOffer: CustomerOrderOffer | null,
    hasFollowupReport: boolean,
    paymentBlocks = false,
): number | null {
    if (status === 'completed') return 10;

    /*
     * The whole point of the derived rung. `delivered` means repairs are done
     * and the charge has been opened — not that the car may be collected. While
     * the charge is outstanding the order stands *on* the payment rung, so
     * `vehicle_ready` is still ahead of it and renders as locked. Nothing about
     * the persisted status changes.
     */
    if (status === 'delivered') return paymentBlocks ? 8 : 9;

    if (CLOSING_STATUSES.has(status)) return 7;

    /*
     * The follow-up report is what completes the reinspection stage, because a
     * B2C order has no status that says so: CLOSING_STATUSES above are
     * vehicle_returned and invoice_processed, both B2B-only, so without this
     * the stage could never show as reached in this channel at all.
     *
     * The gate on `reinspection` is the whole point. Read unconditionally — as
     * it was — the mere existence of a Nachgutachten dragged the timeline to
     * this rung from wherever the order really stood, marking the offer, the
     * commissioning and the repair as completed on an order that had done none
     * of them. It also kept the rung lit after a failed reinspection sent the
     * car back to `reworkshop`, where the report exists but no longer describes
     * the finished state; falling through to REPAIR_PHASE_STATUSES is right
     * there, because the car is in the workshop again.
     */
    if (hasFollowupReport && status === 'reinspection') return 7;

    if (REPAIR_PHASE_STATUSES.has(status)) return 6;
    if (status === COMMISSIONED_STATUS) return 5;
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
        case 'workshop_commissioned':
            return findHistoryDate(ctx.statusHistory, new Set([COMMISSIONED_STATUS]), status);
        case 'in_repair':
            return findHistoryDate(ctx.statusHistory, REPAIR_PHASE_STATUSES, status);
        case 'followup_completed':
            // The follow-up inspection's own moment. It used to borrow the
            // date of `delivered`/`completed`, which put the wrong timestamp on
            // the step as soon as those became distinct events.
            return nachgutachtenDoc?.created_at ?? findHistoryDate(ctx.statusHistory, new Set(['reinspection', ...CLOSING_STATUSES]), status);
        case 'awaiting_payment':
            // Both rungs date from `delivered`: that is when repairs finished
            // and the charge was opened. They are one moment presented as two
            // steps, which is the whole idea.
            return findHistoryDate(ctx.statusHistory, new Set(['delivered']));
        case 'vehicle_ready':
            return findHistoryDate(ctx.statusHistory, new Set(['delivered']));
        case 'case_closed':
            return findHistoryDate(ctx.statusHistory, new Set(['completed']));
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

/**
 * Every rung from the follow-up inspection onwards carries the invoice link.
 * `case_closed` is in the list because a finished order rests on it, and its
 * own subtitle promises the documents stay available — leaving it out was the
 * one place that promise was not kept.
 */
const INVOICE_STAGES = new Set<CustomerOrderStage>(['followup_completed', 'awaiting_payment', 'vehicle_ready', 'case_closed']);

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
        /** Passed over: the order moved beyond this rung without taking it. */
        skipped?: boolean;
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
            // Rejecting an offer leaves no live offer, so the timeline lands
            // back here — and used to say only "view your report", as though
            // the customer had never decided anything. B2C has no separate
            // "obtaining quotations" rung to carry the news the way B2B does,
            // so this stage carries it while it is the current one.
            subtitle =
                state.isCurrent && hasRejectedOffer(ctx)
                    ? 'Sie haben das letzte Angebot abgelehnt. Leasyback erstellt Ihnen ein neues Angebot.'
                    : 'Hier können Sie Ihr Gutachten einsehen';
            break;
        case 'offers_published':
            subtitle = 'Bitte geben Sie ein Angebot Ihrer Wahl innerhalb von 72 Stunden frei';
            break;
        case 'offer_approved':
            subtitle = offerApprovedSubtitle(relevantOffer);
            break;
        case 'workshop_commissioned':
            subtitle = repairScheduleSubtitle(ctx.collection) || 'Die Werkstatt stimmt nun einen Reparaturtermin ab';
            break;
        case 'in_repair':
            subtitle = repairScheduleSubtitle(ctx.collection) || 'Nach der Reparatur erfolgt automatisch eine Nachbegutachtung durch den Gutachter';
            break;
        // Both of these promised an invoice unconditionally, and one of them
        // promised paying it. The invoice link renders only where a Rechnung is
        // actually attached, and there is no payment feature at all — the
        // timeline's own pay control is permanently disabled — so a customer
        // with neither was being told to do two impossible things. Mention the
        // invoice where there is one; say nothing about paying until there is
        // something to pay with.
        case 'followup_completed':
            subtitle = rechnungDoc ? 'Hier können Sie Ihr Gutachten und Ihre Rechnung einsehen' : 'Hier können Sie Ihr Gutachten einsehen';
            break;
        case 'awaiting_payment': {
            const stage = repairPaymentStage(ctx);
            const audience = ctx.audience ?? 'customer';

            label = PAYMENT_STAGE_LABEL[stage][audience];
            subtitle = paymentStageSubtitle(stage, audience, ctx.repairPayment?.amount_cents ?? null);
            break;
        }
        case 'vehicle_ready':
            subtitle = rechnungDoc
                ? 'Ihr Fahrzeug kann nun abgeholt werden.\nHier können Sie Ihre Rechnung einsehen'
                : 'Ihr Fahrzeug kann nun abgeholt werden.';
            break;
        case 'case_closed':
            subtitle = 'Der Vorgang ist abgeschlossen.\nAlle Unterlagen bleiben hier für Sie verfügbar';
            break;
    }

    // A rung the order went past without taking. Its normal wording is an
    // instruction ("Bitte geben Sie ein Angebot Ihrer Wahl frei"), which reads
    // as an outstanding demand on a step that is never coming.
    if (state.skipped) {
        subtitle = 'Für diesen Auftrag wurde kein Kundenangebot erstellt — die Reparatur wurde direkt abgestimmt.';
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
        shortLabel: state.isCancelled
            ? 'Auftrag storniert'
            : state.isRejected
              ? 'Wunschtermin abgelehnt'
              : // The headline the page header and the Admin status header both
                // read comes from here, so the payment rung has to carry its
                // derived wording into shortLabel too — otherwise the timeline
                // says "Zahlung erforderlich" while the header above it still
                // says "Abholbereit".
                stage === 'awaiting_payment'
                ? PAYMENT_STAGE_LABEL[repairPaymentStage(ctx)][ctx.audience ?? 'customer']
                : STAGE_SHORT_LABEL[stage],
        subtitle,
        tooltipDescription: state.isCancelled
            ? 'Dieser Auftrag wurde storniert und wird nicht weiter bearbeitet.'
            : stage === 'awaiting_payment'
              ? PAYMENT_STAGE_TOOLTIP[repairPaymentStage(ctx)][ctx.audience ?? 'customer']
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

    if (stage === 'followup_completed' && nachgutachtenDoc) {
        step.reportDocUrl = resolveDocUrl(nachgutachtenDoc);
    }

    // Moved off `followup_completed`, where it sat next to the report links and
    // meant nothing in particular. It belongs on the rung that is actually
    // about paying, and only when this viewer can settle it — `payable` is
    // decided server-side and is already false for Admin.
    if (stage === 'awaiting_payment' && ctx.repairPayment?.payable) {
        step.showPaymentAction = true;
    }

    if (rechnungDoc && INVOICE_STAGES.has(stage)) {
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
        tooltipDescription: state.isCancelled ? 'Dieser Auftrag wurde storniert und wird nicht weiter bearbeitet.' : B2B_STAGE_TOOLTIP[stage],
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
        const priorIndex = Math.min(resolveB2bProgressIndex(priorStatus, relevantOffer) ?? 0, B2B_ORDER_STAGE_SEQUENCE.length - 1);
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
        const isCurrent = index === progressIndex;
        const completed = index < progressIndex || (isCurrent && status === CLOSED_SUCCESSFULLY);
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
    // Published only, matching getB2bOrderFlowSteps(). An unpublished report is
    // a draft the customer cannot see, so it must not advance their timeline —
    // and Admin renders these same steps from a payload that *does* carry
    // drafts, which is where the unfiltered read showed a stage as reached on
    // the strength of a document nobody had released yet.
    const reportDocuments = (ctx.reportDocuments ?? []).filter((doc) => doc.published !== false);
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

    const progressIndex = resolveProgressIndex(status, relevantOffer, !!nachgutachtenDoc, repairPaymentBlocksPickup(ctx));

    if (progressIndex === null) {
        return null;
    }

    let nextAssigned = false;

    return CUSTOMER_ORDER_STAGE_SEQUENCE.map((stage, index) => {
        const reached = index < progressIndex;

        // Reached, but with nothing to show for it — the repair was arranged
        // without ever going through an offer. Neither ticked nor pending: it is
        // a step this order did not take.
        const skipped = reached && !stageHappened(stage, ctx);
        const isCurrent = index === progressIndex;
        const completed = (reached && !skipped) || (isCurrent && status === CLOSED_SUCCESSFULLY);
        const isUpcoming = index > progressIndex;
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
            skipped,
        });
    });
}

/**
 * Statuses that leave a vehicle free for a new order — the frontend mirror of
 * OrderStatus::reorderableValues(), which VehicleService::blocksNewOrder() and
 * the create-order endpoint enforce.
 *
 * `delivered` used to be in here, left behind from when it was a terminal
 * status; the button it produced was refused by the server every time, because
 * a car nobody has collected still holds its vehicle. `completed` used to be
 * here too, and is now deliberately out: a vehicle that has been through the
 * process is done with it.
 */
const REORDERABLE_ORDER_STATUSES = new Set(['cancelled', 'discarded']);

/**
 * What the vehicle's order button should offer, if anything.
 *
 * `start` for a vehicle that has never been ordered for, `restart` where every
 * order it has was called off — the same creation flow either way, but the
 * wording has to differ or a reorder reads as though nothing had happened
 * before it. `null` covers both "an order is running" and "the case is closed
 * for good", which is why this returns an action rather than a boolean: those
 * two say nothing and there is nothing to distinguish.
 */
export type NewOrderAction = 'start' | 'restart';

export function newOrderAction(orders: ReadonlyArray<{ order_status: string }>): NewOrderAction | null {
    if (!orders.every((order) => REORDERABLE_ORDER_STATUSES.has((order.order_status ?? '').trim()))) {
        return null;
    }

    return orders.length > 0 ? 'restart' : 'start';
}

/** The button's own wording, kept next to the rule that decides it. */
export const NEW_ORDER_ACTION_LABEL: Record<NewOrderAction, string> = {
    start: 'Vorgang starten',
    restart: 'Vorgang erneut starten',
};

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
