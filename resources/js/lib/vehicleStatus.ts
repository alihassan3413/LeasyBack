import type { BadgeVariants } from '@/components/ui/badge';

export interface VehicleStatusDisplay {
    label: string;
    variant: NonNullable<BadgeVariants['variant']>;
}

/** Matches the OrderStatus PHP enum (app/Enums/OrderStatus.php). */
const ORDER_STATUS_DISPLAY: Record<string, VehicleStatusDisplay> = {
    order_requested: { label: 'Angefragt', variant: 'secondary' },
    order_placed: { label: 'Bestellt', variant: 'secondary' },
    confirmed: { label: 'Bestätigt', variant: 'default' },
    inspected: { label: 'Begutachtet', variant: 'default' },
    workshop: { label: 'In der Werkstatt', variant: 'warning' },
    reinspection: { label: 'Nachbegutachtung', variant: 'warning' },
    reworkshop: { label: 'Erneut in der Werkstatt', variant: 'warning' },
    delivered: { label: 'Abholbereit', variant: 'warning' },
    vehicle_collected: { label: 'Fahrzeug abgeholt', variant: 'default' },
    workshop_commissioned: { label: 'Werkstatt beauftragt', variant: 'warning' },
    repair_completed: { label: 'Reparatur abgeschlossen', variant: 'warning' },
    vehicle_returned: { label: 'Fahrzeug zurückgegeben', variant: 'default' },
    invoice_processed: { label: 'Rechnung verarbeitet', variant: 'default' },
    completed: { label: 'Abgeschlossen', variant: 'success' },
    discarded: { label: 'Verworfen', variant: 'outline' },
    cancelled: { label: 'Storniert', variant: 'outline' },
};

const NOT_STARTED: VehicleStatusDisplay = { label: 'Eingeplant', variant: 'warning' };

/** A vehicle with no orders yet shows "Eingeplant"; otherwise its latest order's status. */
export function getVehicleStatusDisplay(latestOrderStatus: string | null | undefined, repairStage?: string | null): VehicleStatusDisplay {
    if (!latestOrderStatus) {
        return NOT_STARTED;
    }

    const display = ORDER_STATUS_DISPLAY[latestOrderStatus] ?? { label: latestOrderStatus, variant: 'secondary' as const };
    const derived = getOrderStatusLabel(latestOrderStatus, repairStage);

    return derived === display.label ? display : { label: derived, variant: 'warning' };
}

export function isVehicleCompleted(latestOrderStatus: string | null | undefined): boolean {
    return latestOrderStatus === 'completed';
}

/**
 * The single source of truth for raw `order_status` wording, shared by every
 * surface that shows one — customer and Admin alike. Admin owns its own pill
 * colours (lib/adminStatus.ts) but must not own a second copy of these labels:
 * that duplication is what let `order_placed` read "Angefragt" in Admin while
 * the customer saw "Bestellt" for the same order (QA Bug 11).
 */
export const ORDER_STATUS_LABELS: Record<string, string> = {
    order_requested: 'Anfrage gesendet',
    order_placed: 'Bestellt',
    confirmed: 'Bestätigt',
    inspected: 'Geprüft',
    workshop: 'In Werkstatt',
    reinspection: 'Nachprüfung',
    reworkshop: 'Erneut in Werkstatt',
    delivered: 'Abholbereit',
    vehicle_collected: 'Fahrzeug abgeholt',
    workshop_commissioned: 'Werkstatt beauftragt',
    repair_completed: 'Reparatur abgeschlossen',
    vehicle_returned: 'Fahrzeug zurückgegeben',
    invoice_processed: 'Rechnung verarbeitet',
    completed: 'Abgeschlossen',
    discarded: 'Verworfen',
    cancelled: 'Storniert',
};

/**
 * Status choices offered by the dashboard filter — the real order statuses,
 * minus the cancelled one.
 */
export const VEHICLE_STATUS_FILTER_OPTIONS: { value: string; label: string }[] = [
    ...Object.entries(ORDER_STATUS_DISPLAY)
        .filter(([value]) => value !== 'cancelled')
        .map(([value, display]) => ({ value, label: display.label })),
];

/**
 * Wording for the derived stages that override a raw status.
 *
 * `delivered` alone says "Abholbereit", which is false while the repair charge
 * is outstanding — the completion gate refuses pickup, and the customer is
 * being shown a pay-now banner at the same time. The stage is computed
 * server-side (App\Support\RepairPaymentPresentation) so every surface that
 * overrides here overrides on the same fact.
 */
const REPAIR_STAGE_LABELS: Record<string, string> = {
    awaiting_payment: 'Zahlung erforderlich',
    payment_processing: 'Zahlung wird verarbeitet',
};

export function getOrderStatusLabel(status: string | null | undefined, repairStage?: string | null): string {
    if (!status) {
        return 'Unbekannt';
    }

    if (status === 'delivered' && repairStage) {
        const derived = REPAIR_STAGE_LABELS[repairStage];

        if (derived) {
            return derived;
        }
    }

    return ORDER_STATUS_LABELS[status] ?? status.replace(/_/g, ' ');
}
