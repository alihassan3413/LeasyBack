<?php

namespace App\Support;

use App\Enums\OrderStatus;

/**
 * A vehicle's orders split into the one the portal speaks for and the closed
 * ones behind it.
 *
 * A vehicle accumulates orders over its life: every reorder is its own record
 * with its own Auftragsnummer, and none of them is ever rewritten. The portal
 * used to pick the one to render with `orders[0]` — a position in whatever
 * order the query happened to return, not a rule — which meant the rest of a
 * vehicle's history had no address at all. The rule is stated once here and
 * its answer travels in the payload, so no client re-derives it and no two
 * clients can disagree about which order is the live one.
 */
final class OrderHistory
{
    /**
     * The current order, plus a summary of each order behind it, newest first.
     *
     * "Current" is the newest order that has not closed. When none has — every
     * order completed, cancelled or discarded — it is the newest closed one,
     * so a finished vehicle still shows the case it went through instead of an
     * empty page. That fallback is also why an order can be both closed and
     * current, and why `is_closed` is stated explicitly rather than inferred
     * from which half of the split an order landed in.
     *
     * @param  list<array<string, mixed>>  $orders  newest first
     * @return array{current_order: array<string, mixed>|null, order_history: list<array<string, mixed>>}
     */
    public static function split(array $orders): array
    {
        $current = null;

        foreach ($orders as $order) {
            if (! self::isClosed($order['order_status'] ?? null)) {
                $current = $order;
                break;
            }
        }

        $current ??= $orders[0] ?? null;

        $history = [];

        foreach ($orders as $order) {
            if ($current !== null && $order['id'] === $current['id']) {
                continue;
            }

            $history[] = self::summarise($order);
        }

        return ['current_order' => $current, 'order_history' => $history];
    }

    /**
     * One order reduced to what a history row shows.
     *
     * Deliberately not the whole order: the full record — timeline, offers,
     * documents, payment — is fetched by id when the customer opens it
     * (VehicleService::findOrderDetail()), so a vehicle with a long history
     * does not carry every one of them into every dashboard response.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public static function summarise(array $order): array
    {
        $status = (string) ($order['order_status'] ?? '');

        return [
            'id' => $order['id'],
            'auftragsnummer' => $order['auftragsnummer'],
            'order_status' => $status,
            'outcome' => self::outcome($status),
            'is_closed' => self::isClosed($status),
            'created_at' => $order['created_at'] ?? null,
            'closed_at' => self::closedAt($order),
            'offer_count' => count($order['offers'] ?? []),
            'document_count' => count($order['report_documents'] ?? []),
            // A cancellation fee outlives the order that incurred it, so a
            // closed row can still be the one holding an open obligation.
            'has_open_payment' => self::hasOpenPayment($order),
        ];
    }

    /**
     * How the order ended, as a single word the client can render without
     * re-reading the status table: `completed`, `cancelled`, `discarded`, or
     * `open` while it is still running.
     */
    public static function outcome(?string $status): string
    {
        if ($status === null || ! self::isClosed($status)) {
            return 'open';
        }

        return in_array($status, OrderStatus::completedValues(), true) ? 'completed' : $status;
    }

    public static function isClosed(?string $status): bool
    {
        return $status !== null && in_array($status, OrderStatus::closedValues(), true);
    }

    /**
     * When the order reached its closing status, from its own status trail.
     *
     * The trail arrives newest-first, so the first closing entry is the
     * transition that actually ended it — an order reopened and closed again
     * is dated by the last closure, not the first.
     *
     * @param  array<string, mixed>  $order
     */
    private static function closedAt(array $order): ?string
    {
        if (! self::isClosed($order['order_status'] ?? null)) {
            return null;
        }

        foreach ($order['status_updates'] ?? [] as $update) {
            if (self::isClosed($update['new_status'] ?? null)) {
                return $update['created_at'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private static function hasOpenPayment(array $order): bool
    {
        $payment = $order['payment'] ?? null;

        if (! is_array($payment)) {
            return false;
        }

        return ($payment['requires_setup'] ?? false)
            || ($payment['repair']['payable'] ?? false)
            || ($payment['cancellation_fee']['payable'] ?? false);
    }
}
