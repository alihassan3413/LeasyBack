import { getOrderStatusLabel } from '@/lib/vehicleStatus';

const CORE_PATH = ['confirmed', 'inspected', 'workshop', 'delivered', 'completed'] as const;

const STATUS_CORE_INDEX: Record<string, number> = {
    order_requested: -1,
    order_placed: -1,
    confirmed: 0,
    inspected: 1,
    workshop: 2,
    reinspection: 2,
    reworkshop: 2,
    delivered: 3,
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
