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
    final_total_gross: string | number | null;
    additional_notes: string | null;
}
