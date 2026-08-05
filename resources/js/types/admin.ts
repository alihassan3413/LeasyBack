import type { B2bOfferPresentationData, OrderCollectionData } from './order';
import type { OrderRequestPayload } from './vehicle';

/** Matches AdminQueryService::summary()'s response shape. */
export interface AdminSummaryData {
    total_b2c_customers: number | string;
    total_b2b_users: number | string;
    total_b2b_companies: number | string;
    total_vehicles: number | string;
    total_orders: number | string;
    active_orders: number | string;
    delivered_orders: number | string;
    pending_inspections: number | string;
}

export type AdminCustomerType = 'b2c' | 'b2b';

/** Matches AdminQueryService::b2cList()/b2bList()'s per-row shape — B2B-only fields are absent for B2C rows. */
export interface AdminCustomerListItem {
    user_id: number;
    user_email: string;
    is_active: boolean;
    salutation: string | null;
    first_name: string | null;
    last_name: string | null;
    city: string | null;
    country: string | null;
    created_at: string;
    b2b_id?: string;
    company_name?: string;
    role?: string;
}

/** Matches AdminQueryService::b2cList()/b2bList()'s response envelope. */
export interface AdminCustomerList {
    page: number;
    limit: number;
    total: number;
    total_active: number;
    total_inactive: number;
    data: AdminCustomerListItem[];
}

/** Matches AdminQueryService::b2cDetail()/b2bDetail()'s response shape. */
export interface AdminCustomerDetail {
    user_id?: number;
    user_email?: string;
    is_active: boolean;
    created_at: string;
    salutation?: string | null;
    first_name?: string | null;
    last_name?: string | null;
    street?: string | null;
    number?: string | null;
    additional_address?: string | null;
    zip_code?: string | null;
    city?: string | null;
    country?: string | null;
    b2b_id?: string;
    company_name?: string;
    vat_id?: string | null;
    contact_email?: string | null;
    service_fee_amount?: string | null;
    service_fee_effective_from?: string | null;
    members?: { user_id: number; user_email: string; role: string }[];
}

/** Minimal subset of AdminQueryService::vehicles()'s per-row shape, for the customer detail page's Fahrzeuge tab. */
export interface AdminCustomerVehicle {
    vehicle_id: string;
    license_plate: string;
    make: string | null;
    model: string | null;
    vin: string | null;
    leasing_end_date: string | null;
    current_order_status: string | null;
}

/** Minimal subset of AdminQueryService::orders()'s per-row shape, for the customer detail page's Aufträge tab. */
export interface AdminCustomerOrder {
    id: string;
    auftragsnummer: string;
    leasyback_partner: string;
    order_status: string;
    license_plate: string;
    make: string | null;
    model: string | null;
    created_at: string;
}

/** Matches AdminQueryService::enrichVehicles()'s per-row shape (both vehicles() and vehicleDetail() return this). */
export interface AdminVehicleRow {
    vehicle_id: string;
    license_plate: string;
    first_registration_date: string | null;
    leasing_end_date: string | null;
    leasinggeber: string | null;
    vin: string | null;
    make: string | null;
    model: string | null;
    vehicle_belongs: 'B2B' | 'B2C';
    b2b_id: string | null;
    b2c_user_id: number | null;
    assigned_profile_id: number | null;
    created_at: string;
    updated_at: string;
    user_id: number | null;
    user_email: string | null;
    user_type: string | null;
    company_name: string | null;
    current_order_id: string | null;
    current_auftragsnummer: string | null;
    current_order_status: string | null;
    current_order_created_at: string | null;
    /** Allowed next statuses for `current_order_id`, for the list row's action menu. */
    current_order_transitions: string[];
    /** True while an order is neither delivered nor cancelled/discarded — blocks creating another. */
    has_open_order: boolean;
    /** True when a TÜV SÜD order carries a Gutachtennummer the appraisal pull can use. */
    can_pull_documents: boolean;
    order_history: AdminVehicleOrderHistoryEntry[];
    documents: AdminVehicleDocumentEntry[];
}

export interface AdminVehicleOrderHistoryEntry {
    id: string;
    auftragsnummer: string;
    leasyback_partner: string;
    order_status: string;
    sent_at: string | null;
    created_at: string;
    response_status: number | null;
    confirmation_date: string | null;
    report_documents: AdminReportDocument[];
    /** Detail-page only — AdminQueryService::hydrateVehicleDetail() adds these; vehicles() (the list) omits them. */
    request_payload?: OrderRequestPayload | null;
    status_updates?: AdminOrderStatusUpdate[];
    offers?: AdminOfferRow[];
    available_transitions?: string[];
}

/** Matches AdminQueryService::reportDocuments()'s per-row shape (a `vehicle_report_documents` row plus a computed signed_url). */
export interface AdminReportDocument {
    id: string;
    auftragsnummer: string;
    vehicle_id: string;
    document_type: string | null;
    document_title: string | null;
    path: string;
    published: boolean;
    signed_url: string | null;
    created_at: string;
    updated_at: string;
}

export interface AdminVehicleDocumentEntry {
    document_id: string;
    document_category: string;
    document_type: string;
    original_file_name: string;
    content_type: string;
    file_size: number;
    uploaded_by_user_id: number;
    created_at: string;
    /** Detail-page only — see AdminVehicleOrderHistoryEntry's note. */
    url?: string | null;
}

/** Matches AdminQueryService::enrichOrders()'s per-row shape (both orders() and orderDetail() return this). */
export interface AdminOrderRow {
    id: string;
    vehicle_id: string;
    auftragsnummer: string;
    leasyback_partner: string;
    order_status: string;
    sent_at: string | null;
    created_at: string;
    response_status: number | null;
    license_plate: string;
    vin: string | null;
    make: string | null;
    model: string | null;
    user_id: number | null;
    user_email: string | null;
    user_type: string | null;
    b2b_id: string | null;
    company_name: string | null;
    confirmation_date: string | null;
    assessment_documents: unknown[];
    report_documents: AdminReportDocument[];
    /** True when this TÜV SÜD order carries a Gutachtennummer the appraisal pull can use. */
    can_pull_documents: boolean;
}

/** Matches AdminQueryService::orders()'s response envelope. */
export interface AdminOrderList {
    page: number;
    limit: number;
    total: number;
    total_active: number;
    total_confirmed: number;
    total_inspected: number;
    total_delivered: number;
    data: AdminOrderRow[];
}

/** Matches a `leasyback_offers` row (every status — Admin sees drafts/cancelled too, unlike the customer-facing endpoint). */
export interface AdminOfferRow {
    offer_id: string;
    order_id: string;
    auftragsnummer: string;
    offer_sequence: number;
    offer_status: 'draft' | 'published' | 'selected' | 'closed' | 'cancelled' | 'rejected';
    /** B2B only — the frozen record of what was presented, absent on a B2C offer. */
    presentation?: B2bOfferPresentationData | null;
    repair_cost_net: string | number;
    repair_cost_gross: string | number;
    depreciation_value_net: string | number;
    depreciation_value_gross: string | number;
    workshop_repair_quote_net: string | number;
    workshop_repair_quote_gross: string | number;
    missing_parts_cost_net: string | number;
    missing_parts_cost_gross: string | number;
    final_total_net: string | number;
    final_total_gross: string | number;
    additional_notes: string | null;
    cancellation_reason: string | null;
    published_at: string | null;
    selected_at: string | null;
    cancelled_at: string | null;
    created_at: string;
}

/** Matches a `leasyback_order_status_updates` row. */
export interface AdminOrderStatusUpdate {
    id: string;
    auftragsnummer: string;
    old_status: string;
    new_status: string;
    updated_by: string;
    auth_source: string;
    created_at: string;
}

/** Matches one OrderTaskResolver action — the endpoint the task can fire directly. */
export interface AdminOrderTaskAction {
    method: 'post' | 'patch';
    url: string;
    payload: Record<string, string>;
    label: string;
}

/** The single emphasised open action OrderTaskResolver derives for a B2B order. */
export interface AdminOrderTask {
    key: string;
    title: string;
    description: string;
    state: 'open' | 'waiting';
    date: string | null;
    date_label: string;
    section: string;
    action: AdminOrderTaskAction | null;
}

/** A step OrderTaskResolver already considers satisfied — compact by design. */
export interface AdminOrderTaskHistoryEntry {
    key: string;
    title: string;
    date: string | null;
    section: string;
    state: 'done';
}

/** Matches OrderTaskResolver::forOrderDetail(); null for every B2C order. */
export interface AdminOrderTasks {
    next: AdminOrderTask | null;
    history: AdminOrderTaskHistoryEntry[];
    is_closed: boolean;
    closed_status: string | null;
}

/** Matches AdminQueryService::orderDetail()'s response shape. */
export interface AdminOrderDetail extends AdminOrderRow {
    offers: AdminOfferRow[];
    status_updates: AdminOrderStatusUpdate[];
    /** Never includes `order_placed` (approve()'s job) or `discarded` (reject — not yet a confirmed feature). */
    available_transitions: string[];
    vehicle_belongs: 'B2B' | 'B2C';
    collection: OrderCollectionData | null;
    tasks: AdminOrderTasks | null;
    /** B2B only — null on a B2C order, which has no appraisal-position workflow. */
    appraisal_positions: AdminAppraisalPosition[] | null;
    appraisal_totals: AdminAppraisalTotals | null;
    /** B2B only — null on a B2C order, which has no workshop quotation workflow. */
    workshop_quotations: AdminWorkshopQuotation[] | null;
    /** B2B only — null on a B2C order, which has no internal billing record. */
    billing: AdminOrderBilling | null;
    /** Both audiences. null for a B2C order, which has no note surface. */
    notes: AdminOrderNote[] | null;
}

/**
 * Internal billing state. No accounting or payment integration stands behind
 * this — `is_processed` is the fact the §21 completion gate reads. A future
 * Stripe phase will add payment fields and further `billing_status` values.
 */
export interface AdminOrderBilling {
    billing_status: string;
    invoice_reference: string | null;
    invoice_document_id: string | null;
    processed_at: string | null;
    is_processed: boolean;
}

/**
 * One order note (§16). Admin-authored, with an explicit audience.
 *
 * `visibility` is present only on the Admin payload — the customer's copy of a
 * note omits the field entirely, since they can only ever receive the
 * customer-visible ones.
 */
export interface AdminOrderNote {
    id: string;
    visibility: 'internal' | 'customer';
    body: string;
    /** Snapshot taken at write time; survives the author's account deletion. */
    author_name: string;
    created_at: string | null;
}

/** One row of the appraisal-vs-workshop comparison. All amounts net. */
export interface AdminWorkshopComparisonRow {
    appraisal_position_id: string;
    component: string;
    appraisal_amount_net: string;
    /** null until the workshop has submitted, or when the position is not repairable. */
    workshop_amount_net: string | null;
    /** appraisal − workshop; positive means the workshop is cheaper. */
    difference_net: string | null;
    repair_method: string | null;
    not_repairable: boolean;
}

/**
 * A workshop's invitation to quote and its submission. `status` is derived
 * server-side from the timestamps, never stored as a column.
 */
export interface AdminWorkshopQuotation {
    id: string;
    workshop_label: string;
    invited_email: string | null;
    status: 'invited' | 'submitted' | 'expired' | 'revoked';
    shows_appraisal_amounts: boolean;
    expires_at: string | null;
    submitted_at: string | null;
    revoked_at: string | null;
    company_name: string | null;
    contact_person: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    earliest_repair_start: string | null;
    processing_days: number | null;
    total_net: string | null;
    cannot_repair_for_amount: boolean;
    cannot_repair_note: string | null;
    appraisal_total_net: string;
    comparison: AdminWorkshopComparisonRow[];
}

/**
 * One repair position of the initial appraisal. All amounts are net strings as
 * they come off a `decimal:2` cast — never render them as gross (b2b.txt §9).
 */
export interface AdminAppraisalPosition {
    id: string;
    sort_order: number;
    component: string;
    damage_description: string | null;
    original_amount_net: string;
    chargeable_amount_net: string | null;
    /** `chargeable_amount_net` when set, otherwise `original_amount_net`. */
    effective_amount_net: string;
    repair_method: string | null;
    /** `manual` for hand-entered rows; `extracted` is reserved for a future PDF extractor. */
    source: 'manual' | 'extracted';
    damage_image_document_ids: string[];
}

export interface AdminAppraisalTotals {
    count: number;
    original_total_net: string;
    chargeable_total_net: string;
}

/** Matches AdminQueryService::vehicles()'s response envelope. */
export interface AdminVehicleList {
    page: number;
    limit: number;
    total: number;
    total_active: number;
    total_completed: number;
    total_confirmed: number;
    total_inspected: number;
    total_delivered: number;
    data: AdminVehicleRow[];
}
