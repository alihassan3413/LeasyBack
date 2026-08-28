import type { RepairPaymentStage } from '@/lib/customerOrderFlow';
import type { OfferData, OrderCollectionData } from './order';

export interface VehicleDocumentData {
    document_id: string;
    document_type: string;
    original_file_name: string;
    url: string | null;
    created_at: string;
}

export interface OrderStatusUpdateData {
    id: string;
    bewertung_id: string | null;
    old_status: string | null;
    new_status: string | null;
    /** Coarse actor role — see VehicleService::hydrateVehicles()'s note on why only this is exposed. */
    auth_source: string | null;
    created_at: string;
}

export interface OrderConfirmationData {
    id: string;
    auftragsnummer: string;
    confirmation_date: string;
    created_at: string;
}

export interface OrderReportDocumentData {
    id: string;
    document_type: string | null;
    document_title: string | null;
    url: string | null;
    published: boolean;
    created_at: string;
    updated_at: string;
}

export interface OrderRequestPayload {
    ansprechpartner?: {
        name?: string;
        telefon?: string;
        email?: string;
    };
    besichtigungsort?: {
        termin?: string;
        name?: string;
        strasse?: string;
        plz?: string;
        ort?: string;
        land?: string;
    };
}

export interface VehicleOrderData {
    id: string;
    auftragsnummer: string;
    leasyback_partner: string;
    sent_at: string | null;
    request_payload: OrderRequestPayload | null;
    response_status: number | null;
    order_status: string;
    created_by_user_id: number | null;
    created_at: string;
    status_updates: OrderStatusUpdateData[];
    order_confirmations: OrderConfirmationData[];
    report_documents: OrderReportDocumentData[];
    offers: OfferData[];
    collection?: OrderCollectionData | null;
    /**
     * Customer-visible notes only (§16). B2B orders only; internal notes have
     * no path into this payload — B2bOrderNoteService::forCustomerOrders()
     * applies the visibility scope and takes no flag that could widen it.
     */
    notes?: CustomerOrderNote[];
    /** B2C orders only — the B2B channel has no customer-card flow. */
    payment?: OrderPaymentState | null;
}

export interface OrderPaymentState {
    /**
     * True only when the viewer can actually act on it: never for Admin, who
     * is refused by OrderPolicy::pay, and never for a closed order.
     */
    requires_setup: boolean;
    status: string;
    card: { brand: string | null; last4: string | null; exp_month: number | null; exp_year: number | null } | null;
    /**
     * How `delivered` should be presented, derived server-side from the order
     * status and the repair charge — see App\Support\RepairPaymentPresentation.
     * Every surface that could otherwise contradict another reads this.
     */
    repair_stage: RepairPaymentStage;
    /** Absent until the order reaches `delivered` and a charge is opened. */
    repair?: OrderRepairPaymentState | null;
    /**
     * Absent unless the customer cancelled the order themselves. A separate
     * obligation from `repair` — an order can carry both, and one settling
     * says nothing about the other.
     */
    cancellation_fee?: OrderCancellationFeeState | null;
}

export interface OrderCancellationFeeState {
    status: string;
    amount_cents: number;
    currency: string;
    paid_at: string | null;
    /** Whether this viewer can settle it now — already false for Admin. */
    payable: boolean;
}

export interface OrderRepairPaymentState {
    status: string;
    amount_cents: number;
    currency: string;
    paid_at: string | null;
    blocks_pickup: boolean;
    /**
     * Whether this viewer can settle it now — already false for Admin and for
     * anything already paid, so no client-side rule has to agree with the
     * server's.
     */
    payable: boolean;
}

/**
 * One customer-visible order note. Deliberately has no `visibility` field: the
 * customer can only ever receive customer-visible notes, so the discriminator
 * is meaningless to them and is omitted server-side.
 */
export interface CustomerOrderNote {
    id: string;
    body: string;
    author_name: string;
    created_at: string | null;
}

export interface VehicleCollectionAddress {
    street: string | null;
    number: string | null;
    additional_address: string | null;
    zip_code: string | null;
    city: string | null;
    country: string | null;
}

/** How an order ended. Matches App\Support\OrderHistory::outcome(). */
export type OrderOutcome = 'open' | 'completed' | 'cancelled' | 'discarded';

/**
 * One row of a vehicle's Auftragsverlauf — matches
 * App\Support\OrderHistory::summarise().
 *
 * Deliberately a summary and not a `VehicleOrderData`: the full record is
 * fetched by id (`orders.show`) when the customer opens it, so a vehicle with
 * a long history does not carry every timeline, offer list and document set
 * into every dashboard response.
 */
export interface OrderHistoryEntry {
    id: string;
    auftragsnummer: string;
    order_status: string;
    outcome: OrderOutcome;
    is_closed: boolean;
    created_at: string;
    /** When it reached its closing status; null while it is still running. */
    closed_at: string | null;
    offer_count: number;
    document_count: number;
    /** A cancellation fee outlives its order, so a closed row can still owe money. */
    has_open_payment: boolean;
}

/** Matches VehicleService::listVehiclesWithOrders()'s per-vehicle response shape. */
export interface VehicleData {
    vehicle_id: string;
    license_plate: string;
    first_registration_date: string | null;
    leasing_end_date: string | null;
    leasinggeber: string | null;
    vin: string | null;
    make: string | null;
    model: string | null;
    vehicle_belongs: 'B2B' | 'B2C';
    created_at: string;
    updated_at: string;
    /**
     * The order every surface speaks for, decided server-side by
     * App\Support\OrderHistory::split() — the newest one still running, or
     * the newest closed one when the vehicle's case is over. Never
     * `orders[0]`, which was a position rather than a rule.
     */
    current_order: VehicleOrderData | null;
    /** Every other order, newest first. Opened by id, not by index. */
    order_history: OrderHistoryEntry[];
    /**
     * Every order, full-fidelity. Still here because a few things are
     * genuinely vehicle-wide rather than order-scoped: the payment banners
     * scan all orders (a cancellation fee is owed on an order that has already
     * closed) and the document lists span them. Nothing may read a *position*
     * out of it.
     */
    orders: VehicleOrderData[];
    documents: VehicleDocumentData[];
    mileage?: number | null;
    contract_number?: string | null;
    cost_centre?: string | null;
    driver_name?: string | null;
    driver_contact?: string | null;
    collection_address?: VehicleCollectionAddress | null;
}

/** One rejected row from a bulk import, as returned by VehicleImportService. */
export interface VehicleImportRowError {
    /** 1-based row number in the uploaded file, so the user can find it. */
    row: number;
    license_plate: string | null;
    messages: string[];
}

/** Matches VehicleImportService::import()'s return shape. */
export interface VehicleImportResult {
    total: number;
    imported: number;
    rejected: number;
    /** True when the file held more rows than the importer processes. */
    truncated: boolean;
    /** Headings that matched no known field and were skipped. */
    ignored_columns: string[];
    errors: VehicleImportRowError[];
}

/**
 * The vehicle as the order detail page receives it — matches
 * VehicleService::findOrderDetail()'s `vehicle`.
 *
 * The order list is gone (the one order being rendered travels separately) and
 * `current_order` is reduced to a summary, which is all the page needs to say
 * which of the vehicle's orders is the live one and link to it.
 */
export interface OrderDetailVehicle extends Omit<VehicleData, 'orders' | 'current_order'> {
    current_order: OrderHistoryEntry | null;
}
