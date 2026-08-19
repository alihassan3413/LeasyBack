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
    /** Confirmed workshop repair appointment (§11). Customer-visible business date. */
    confirmed_repair_start_date: string | null;
    estimated_processing_days: number | null;
    collection_address: OrderCollectionAddress | null;
    collection_note: string | null;
    /** Admin-only — absent from every customer payload (§16). */
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
    offer_status: 'draft' | 'published' | 'selected' | 'closed' | 'cancelled' | 'rejected';
    repair_cost_net: string | number | null;
    depreciation_value_net: string | number | null;
    workshop_repair_quote_net: string | number | null;
    missing_parts_cost_net: string | number | null;
    final_total_net: string | number | null;
    /** Gross keys are absent entirely on a B2B payload (b2b.txt §9). */
    repair_cost_gross?: string | number | null;
    depreciation_value_gross?: string | number | null;
    workshop_repair_quote_gross?: string | number | null;
    missing_parts_cost_gross?: string | number | null;
    final_total_gross?: string | number | null;
    /**
     * The frozen record of what was presented, in either channel. Null on a
     * manually created fallback offer — which is exactly how the two are told
     * apart, since only a quotation-backed offer has one.
     */
    presentation?: B2bOfferPresentationData | null;
    additional_notes: string | null;
    published_at: string | null;
    selected_at: string | null;
}

/**
 * One presented repair position. Net amounts always; the gross trio is present
 * only where the channel shows gross (OfferPricingPolicy), derived server-side
 * from the rate frozen on the presentation.
 */
export interface B2bOfferPresentationLine {
    appraisal_position_id: string;
    component: string;
    damage_description: string | null;
    appraisal_amount_net: string;
    repair_amount_net: string | null;
    saving_net: string | null;
    appraisal_amount_gross?: string | null;
    repair_amount_gross?: string | null;
    saving_gross?: string | null;
    repair_method: string | null;
    not_repairable: boolean;
    damage_image_document_ids: string[];
}

/**
 * Snapshotted at publish time, so it keeps showing what the customer actually
 * saw even after the underlying appraisal positions are corrected.
 */
export interface B2bOfferPresentationData {
    /** The quotation this offer was built from — seeds the repair-appointment form. */
    workshop_quotation_id: string | null;
    /** The repairing business, by company name. Contact details stay Admin-only. */
    workshop_name: string | null;
    lines: B2bOfferPresentationLine[];
    appraisal_total_net: string;
    repair_total_net: string;
    saving_net: string;
    /** Present only where the channel shows gross; the rate is the one frozen at publish. */
    vat_rate?: string | null;
    appraisal_total_gross?: string | null;
    repair_total_gross?: string | null;
    saving_gross?: string | null;
    valid_until: string | null;
    is_expired: boolean;
    customer_note: string | null;
    presented_at: string | null;
    rejected_at: string | null;
    customer_comment: string | null;
}
