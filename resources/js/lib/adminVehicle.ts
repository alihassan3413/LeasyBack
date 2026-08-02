import type { AdminVehicleRow } from '@/types/admin';
import type { VehicleData, VehicleOrderData } from '@/types/vehicle';

/**
 * Adapts an `AdminVehicleRow` (AdminQueryService::vehicleDetail()) to the
 * `VehicleData` shape VehicleService::listVehiclesWithOrders() produces, so
 * the Admin vehicle detail page can render the customer dashboard's
 * VehicleExpandedPanel.vue unchanged instead of growing a second, drifting
 * copy of the same timeline/documents/offers/Besichtigungsort UI.
 *
 * The two shapes only differ in naming and in what the list variant omits:
 *
 * - `order_history` → `orders`; `signed_url` → `url` on report documents.
 * - `request_payload` / `status_updates` / `offers` are present only when
 *   the row came from vehicleDetail() (see its hydrateVehicleDetail()); the
 *   list variant leaves them undefined and the panel degrades to its
 *   generic upcoming-steps timeline, which is the correct fallback.
 * - `order_confirmations` has no Admin equivalent — enrichVehicles() folds
 *   confirmations down to a single `confirmation_date` per order. The panel
 *   never reads the array (getCustomerOrderFlowSteps() derives the
 *   confirmation date from `status_updates`), so it maps to `[]` rather
 *   than being faked from `confirmation_date`.
 */
export function toVehicleData(vehicle: AdminVehicleRow): VehicleData {
    return {
        vehicle_id: vehicle.vehicle_id,
        license_plate: vehicle.license_plate,
        first_registration_date: vehicle.first_registration_date,
        leasing_end_date: vehicle.leasing_end_date,
        leasinggeber: vehicle.leasinggeber,
        vin: vehicle.vin,
        make: vehicle.make,
        model: vehicle.model,
        vehicle_belongs: vehicle.vehicle_belongs,
        created_at: vehicle.created_at,
        updated_at: vehicle.updated_at,
        orders: vehicle.order_history.map(toVehicleOrderData),
        documents: vehicle.documents.map((document) => ({
            document_id: document.document_id,
            document_type: document.document_type,
            original_file_name: document.original_file_name,
            url: document.url ?? null,
            created_at: document.created_at,
        })),
    };
}

function toVehicleOrderData(order: AdminVehicleRow['order_history'][number]): VehicleOrderData {
    return {
        id: order.id,
        auftragsnummer: order.auftragsnummer,
        leasyback_partner: order.leasyback_partner,
        sent_at: order.sent_at,
        request_payload: order.request_payload ?? null,
        response_status: order.response_status,
        order_status: order.order_status,
        created_by_user_id: null,
        created_at: order.created_at,
        status_updates: (order.status_updates ?? []).map((update) => ({
            id: update.id,
            bewertung_id: null,
            old_status: update.old_status,
            new_status: update.new_status,
            auth_source: update.auth_source,
            created_at: update.created_at,
        })),
        order_confirmations: [],
        report_documents: order.report_documents.map((document) => ({
            id: document.id,
            document_type: document.document_type,
            document_title: document.document_title,
            url: document.signed_url,
            published: document.published,
            created_at: document.created_at,
            updated_at: document.updated_at,
        })),
        offers: order.offers ?? [],
    };
}
