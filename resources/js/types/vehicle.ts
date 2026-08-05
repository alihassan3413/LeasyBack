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
