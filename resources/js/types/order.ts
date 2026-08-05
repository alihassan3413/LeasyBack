export interface OrderCollectionAddress {
    street: string | null;
    number: string | null;
    additional_address: string | null;
    zip_code: string | null;
    city: string | null;
    country: string | null;
}

/** Matches OrderCollectionService::forOrders(). B2B orders only; `internal_note` is Admin-only. */
export interface OrderCollectionData {
    requested_collection_date: string | null;
    confirmed_collection_date: string | null;
    collection_address: OrderCollectionAddress | null;
    collection_note: string | null;
    internal_note?: string | null;
}

export interface StationData {
    station_id: string;
    provider: string;
    name: string;
    strasse: string;
    plz: string;
    ort: string;
    bundesland: string | null;
    land: string;
}

/** Matches VehicleService::listVehiclesWithOrders()'s per-order `offers` shape — published/selected only. */
export interface OfferData {
    offer_id: string;
    offer_sequence: number;
    offer_status: 'draft' | 'published' | 'selected' | 'closed' | 'cancelled';
    repair_cost_net: string | number | null;
    repair_cost_gross: string | number | null;
    depreciation_value_net: string | number | null;
    depreciation_value_gross: string | number | null;
    workshop_repair_quote_net: string | number | null;
    workshop_repair_quote_gross: string | number | null;
    missing_parts_cost_net: string | number | null;
    missing_parts_cost_gross: string | number | null;
    final_total_net: string | number | null;
    final_total_gross: string | number | null;
    additional_notes: string | null;
    published_at: string | null;
    selected_at: string | null;
}
