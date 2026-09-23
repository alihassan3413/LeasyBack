import { getOrderStatusLabel, ORDER_STATUS_LABELS } from '@/lib/vehicleStatus';

export interface StatusPillStyle {
    label: string;
    background: string;
    color: string;
}

interface PillColors {
    background: string;
    color: string;
}

/**
 * Admin-specific pill colours only. The wording comes from
 * ORDER_STATUS_LABELS (lib/vehicleStatus.ts) so Admin and the customer can
 * never disagree about what a status is called.
 *
 * `order_requested` was missing here even though ADMIN_ORDER_STATUS_FILTERS
 * offers it as a filter, so a freshly-requested order fell through to the
 * fallback colours.
 */
const ADMIN_DASHBOARD_STATUS_COLORS: Record<string, PillColors> = {
    order_requested: { background: 'rgba(148, 163, 184, 0.16)', color: '#475569' },
    order_placed: { background: 'rgba(239, 132, 80, 0.12)', color: '#c0622e' },
    confirmed: { background: 'rgba(99, 102, 241, 0.12)', color: '#4f46e5' },
    inspected: { background: 'rgba(1, 185, 144, 0.12)', color: '#00856a' },
    workshop: { background: 'rgba(245, 158, 11, 0.12)', color: '#b45309' },
    reinspection: { background: 'rgba(124, 58, 237, 0.12)', color: '#6d28d9' },
    reworkshop: { background: 'rgba(234, 88, 12, 0.12)', color: '#c2410c' },
    delivered: { background: 'rgba(16, 57, 59, 0.09)', color: '#10393b' },
    vehicle_collected: { background: 'rgba(99, 102, 241, 0.12)', color: '#4f46e5' },
    workshop_commissioned: { background: 'rgba(245, 158, 11, 0.12)', color: '#b45309' },
    repair_completed: { background: 'rgba(234, 88, 12, 0.12)', color: '#c2410c' },
    vehicle_returned: { background: 'rgba(124, 58, 237, 0.12)', color: '#6d28d9' },
    invoice_processed: { background: 'rgba(16, 57, 59, 0.09)', color: '#10393b' },
    completed: { background: 'rgba(1, 185, 144, 0.12)', color: '#00856a' },
    discarded: { background: 'rgba(107, 114, 128, 0.12)', color: '#374151' },
    cancelled: { background: 'rgba(220, 38, 38, 0.10)', color: '#991b1b' },
};

const FALLBACK_COLORS: PillColors = { background: 'rgba(0, 0, 0, 0.05)', color: '#6f8585' };

export function getAdminDashboardStatus(status: string | null | undefined): StatusPillStyle {
    if (!status) {
        return { label: 'Kein Status', ...FALLBACK_COLORS };
    }

    return {
        label: getOrderStatusLabel(status),
        ...(ADMIN_DASHBOARD_STATUS_COLORS[status] ?? FALLBACK_COLORS),
    };
}

/**
 * Only real `App\Enums\OrderStatus` values belong here — AdminQueryService's
 * filters() rejects anything else with a validation error, so a chip for a
 * status that isn't in the enum doesn't filter, it 302s the page back.
 * `delivered` is the B2C terminal, `completed` the B2B one; both are real
 * enum cases and both are offered.
 *
 * The order is the workflow order shown to Admin, not the enum's; only the
 * labels are shared.
 */
const ADMIN_ORDER_STATUS_FILTER_VALUES: string[] = [
    'order_requested',
    'order_placed',
    'confirmed',
    'vehicle_collected',
    'inspected',
    'workshop_commissioned',
    'workshop',
    'repair_completed',
    'reinspection',
    'reworkshop',
    'vehicle_returned',
    'invoice_processed',
    'delivered',
    'completed',
    'discarded',
    'cancelled',
];

export const ADMIN_ORDER_STATUS_FILTERS: { value: string; label: string }[] = [
    { value: '', label: 'Alle' },
    ...ADMIN_ORDER_STATUS_FILTER_VALUES.map((value) => ({ value, label: ORDER_STATUS_LABELS[value] })),
];
