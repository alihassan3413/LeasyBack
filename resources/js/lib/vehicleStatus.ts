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
