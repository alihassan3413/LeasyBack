import { formatGermanDateTime, type CustomerOrderFlowStep } from '@/lib/customerOrderFlow';
import { getOrderStatusLabel } from '@/lib/vehicleStatus';

const CORE_PATH = ['confirmed', 'inspected', 'workshop', 'delivered', 'completed'] as const;

const STATUS_CORE_INDEX: Record<string, number> = {
    order_requested: -1,
    order_placed: -1,
    confirmed: 0,
    vehicle_collected: 0,
    inspected: 1,
    workshop_commissioned: 1,
    workshop: 2,
    repair_completed: 2,
    reinspection: 2,
    reworkshop: 2,
    delivered: 3,
    vehicle_returned: 3,
    invoice_processed: 3,
    completed: 4,
};

const TERMINAL_STATUSES = new Set(['completed', 'discarded', 'cancelled']);

export interface UpcomingStep {
    status: string;
    label: string;
}

export function getUpcomingSteps(currentStatus?: string | null): UpcomingStep[] {
    const status = (currentStatus ?? '').trim();

    if (!status || TERMINAL_STATUSES.has(status)) {
        return [];
    }

    const index = STATUS_CORE_INDEX[status] ?? -1;

    return CORE_PATH.slice(index + 1).map((coreStatus) => ({
        status: coreStatus,
        label: getOrderStatusLabel(coreStatus),
    }));
}

/** One rendered row of OrderStatusTimeline.vue. */
export interface OrderTimelineEntry {
    datetime: string;
    label: string;
    sublabel?: string;
    completed?: boolean;
    isFuture?: boolean;
    isNext?: boolean;
    isCurrent?: boolean;
    isCancelled?: boolean;
    isRejected?: boolean;
    isReport?: boolean;
    docUrl?: string;
    invoiceUrl?: string;
    showPaymentAction?: boolean;
    tooltipDescription?: string;
}

/**
 * Renders the customer order flow as timeline rows, falling back to the
 * generic "what comes next" list when there is no flow to render (no order,
 * or a status getCustomerOrderFlowSteps() can't place). Lives here rather
 * than in a page so the Admin order detail page and the customer dashboard's
 * VehicleExpandedPanel.vue show the same timeline from the same code.
 */
export function toOrderTimelineEntries(steps: CustomerOrderFlowStep[] | null, fallbackStatus?: string | null): OrderTimelineEntry[] {
    if (steps) {
        return steps.map((step) => ({
            datetime: step.datetime ? formatGermanDateTime(step.datetime) : '',
            label: step.label,
            sublabel: step.subtitle || undefined,
            tooltipDescription: step.tooltipDescription,
            completed: step.completed || step.isCurrent,
            isFuture: !(step.completed || step.isCurrent) && !step.isCancelled && !step.isRejected,
            isNext: step.isNext,
            isCurrent: step.isCurrent,
            isCancelled: step.isCancelled,
            isRejected: step.isRejected,
            isReport: !!(step.reportDocUrl || step.invoiceDocUrl || step.showPaymentAction),
            docUrl: step.reportDocUrl,
            invoiceUrl: step.invoiceDocUrl,
            showPaymentAction: step.showPaymentAction,
        }));
    }

    return getUpcomingSteps(fallbackStatus).map((step, index) => ({
        datetime: '',
        label: step.label,
        completed: false,
        isFuture: true,
        isNext: index === 0,
    }));
}

interface TimelineMarker {
    completed?: boolean;
    isNext?: boolean;
    isCancelled?: boolean;
    isRejected?: boolean;
}

export function timelineDotStyle(entry: TimelineMarker): string {
    if (entry.isCancelled || entry.isRejected) return 'background:#dc2626;border-color:#dc2626';
    if (entry.completed) return 'background:#01B990;border-color:#01B990';
    if (entry.isNext) return 'background:#fff;border-color:#01B990';

    return 'background:#fff;border-color:#B7C2C2';
}

export function timelineLineStyle(entry: TimelineMarker): string {
    if (entry.isCancelled || entry.isRejected) return 'background:#dc2626';

    return entry.completed ? 'background:#01B990' : 'background:#B7C2C2';
}

export function providerDisplayLabel(label: string): string {
    const key = label.toLowerCase();

    if (key === 'dekra') return 'Dekra';
    if (key === 'tuvsud') return 'TÜV SÜD';

    return label;
}
