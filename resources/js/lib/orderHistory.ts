import { formatPortalDate } from '@/lib/portalDate';
import type { OrderHistoryEntry, OrderOutcome, VehicleOrderData } from '@/types/vehicle';

/** Mirrors OrderStatus::closedValues() — the authority stays in PHP. */
const CLOSED_ORDER_STATUSES = new Set(['completed', 'cancelled', 'discarded']);

export function isClosedOrderStatus(status: string | null | undefined): boolean {
    return !!status && CLOSED_ORDER_STATUSES.has(status);
}

export const ORDER_OUTCOME_LABELS: Record<OrderOutcome, string> = {
    open: 'Laufend',
    completed: 'Abgeschlossen',
    cancelled: 'Storniert',
    discarded: 'Verworfen',
};

/** Pill colours per outcome — a cancelled case must not read as a finished one. */
export const ORDER_OUTCOME_PILL: Record<OrderOutcome, string> = {
    open: 'background: rgba(1, 185, 144, 0.12); color: #00856a',
    completed: 'background: #eef3f3; color: #10393b',
    cancelled: 'background: rgba(229, 83, 61, 0.12); color: #b03b28',
    discarded: 'background: #f4f7f6; color: #6f8585',
};

/**
 * When the order's own life ended, or began if it is still running — the one
 * date a history row shows, so the label has to say which it is.
 */
export function orderHistoryDateLabel(entry: OrderHistoryEntry): string {
    const closed = entry.closed_at ? formatPortalDate(entry.closed_at) : '';

    return closed ? `Beendet am ${closed}` : `Gestartet am ${formatPortalDate(entry.created_at)}`;
}

/**
 * The current/history split, in TypeScript.
 *
 * The customer payload already arrives split — App\Support\OrderHistory::split()
 * is the authority and this must never be used to second-guess it. It exists
 * for the one payload that is not split: the Admin vehicle shape, whose
 * `order_history` is *every* order and which lib/adminVehicle.ts adapts into a
 * `VehicleData` so the customer panel can render an Admin surface unchanged.
 */
export function splitOrderHistory(orders: VehicleOrderData[]): {
    current_order: VehicleOrderData | null;
    order_history: OrderHistoryEntry[];
} {
    const current = orders.find((order) => !isClosedOrderStatus(order.order_status)) ?? orders[0] ?? null;

    return {
        current_order: current,
        order_history: orders.filter((order) => order.id !== current?.id).map(summariseOrder),
    };
}

/** The TypeScript half of OrderHistory::summarise(), for the same reason splitOrderHistory() exists. */
export function summariseOrder(order: VehicleOrderData): OrderHistoryEntry {
    const closed = isClosedOrderStatus(order.order_status);

    return {
        id: order.id,
        auftragsnummer: order.auftragsnummer,
        order_status: order.order_status,
        outcome: closed ? (order.order_status as OrderOutcome) : 'open',
        is_closed: closed,
        created_at: order.created_at,
        closed_at: closed ? (order.status_updates.find((update) => isClosedOrderStatus(update.new_status))?.created_at ?? null) : null,
        offer_count: order.offers.length,
        document_count: order.report_documents.length,
        has_open_payment: !!(order.payment?.requires_setup || order.payment?.repair?.payable || order.payment?.cancellation_fee?.payable),
    };
}
