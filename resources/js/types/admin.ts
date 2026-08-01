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
