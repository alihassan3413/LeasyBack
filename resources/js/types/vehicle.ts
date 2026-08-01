import type { OfferData } from './order';

export interface VehicleDocumentData {
    document_id: string;
    document_type: string;
    original_file_name: string;
    created_at: string;
}

export interface OrderStatusUpdateData {
    id: string;
    bewertung_id: string | null;
    old_status: string | null;
    new_status: string | null;
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
    besichtigungsort?: {
        termin?: string;
        name?: string;
        strasse?: string;
        plz?: string;
        ort?: string;
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
}
