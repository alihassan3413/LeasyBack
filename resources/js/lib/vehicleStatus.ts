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
    delivered: { label: 'Abgeschlossen', variant: 'success' },
    discarded: { label: 'Verworfen', variant: 'outline' },
    cancelled: { label: 'Storniert', variant: 'outline' },
};

const NOT_STARTED: VehicleStatusDisplay = { label: 'Eingeplant', variant: 'warning' };

/** A vehicle with no orders yet shows "Eingeplant"; otherwise its latest order's status. */
export function getVehicleStatusDisplay(latestOrderStatus: string | null | undefined): VehicleStatusDisplay {
    if (!latestOrderStatus) {
        return NOT_STARTED;
    }

    return ORDER_STATUS_DISPLAY[latestOrderStatus] ?? { label: latestOrderStatus, variant: 'secondary' };
}

export function isVehicleCompleted(latestOrderStatus: string | null | undefined): boolean {
    return latestOrderStatus === 'delivered';
}

/** German order-status wording used across the dashboard, matching leasyback_web's lib/status.ts. */
const ORDER_STATUS_LABELS: Record<string, string> = {
    order_requested: 'Anfrage gesendet',
    order_placed: 'Bestellt',
    confirmed: 'Bestätigt',
    inspected: 'Geprüft',
    workshop: 'In Werkstatt',
    reinspection: 'Nachprüfung',
    reworkshop: 'Erneut in Werkstatt',
    delivered: 'Geliefert',
    completed: 'Abgeschlossen',
    discarded: 'Verworfen',
    cancelled: 'Storniert',
};

/**
 * Status choices offered by the dashboard filter. Two are not real order
 * statuses: `none` means "no order yet", and `open` means "started but not
 * yet finished or abandoned" — the complement of the closed statuses, which
 * no single value can express. Both are resolved server-side in
 * VehicleService::applyVehicleFilters().
 */
export const VEHICLE_STATUS_FILTER_OPTIONS: { value: string; label: string }[] = [
    { value: 'none', label: 'Eingeplant' },
    { value: 'open', label: 'Laufend' },
    ...Object.entries(ORDER_STATUS_DISPLAY)
        .filter(([value]) => value !== 'cancelled')
        .map(([value, display]) => ({ value, label: display.label })),
];

export function getOrderStatusLabel(status: string | null | undefined): string {
    if (!status) {
        return 'Unbekannt';
    }

    return ORDER_STATUS_LABELS[status] ?? status.replace(/_/g, ' ');
}
