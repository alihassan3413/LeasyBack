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
    offer_status: 'draft' | 'published' | 'selected' | 'closed' | 'cancelled';
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

/** Matches AdminQueryService::orderDetail()'s response shape. */
export interface AdminOrderDetail extends AdminOrderRow {
    offers: AdminOfferRow[];
    status_updates: AdminOrderStatusUpdate[];
    /** Never includes `order_placed` (approve()'s job) or `discarded` (reject — not yet a confirmed feature). */
    available_transitions: string[];
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
