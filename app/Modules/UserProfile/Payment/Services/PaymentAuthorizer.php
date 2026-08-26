<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Enums\OrderStatus;
use App\Models\LeasybackOrder as OrderRecord;
use App\Models\User;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;

/**
 * "May **this user** act on this order's payments?"
 *
 * The authenticated half of the payment authorization story. Its counterpart,
 * PaymentIdentityGuard, answers a different question — "is this Stripe object
 * consistent with the order we resolved it to?" — and the two must not be
 * confused: a Stripe webhook has no user at all, so it cannot use this class,
 * while an authenticated request needs both.
 *
 * Every check answers 404 rather than 403, matching the convention used
 * throughout this application: a non-owner must not be able to tell "this
 * order does not exist" from "it exists but is not yours".
 */
class PaymentAuthorizer
{
    /**
     * Resolve an order the user is allowed to act on the payments of, or null.
     *
     * Null covers every refusal — missing, not theirs, B2B, or closed — on
     * purpose. Callers turn it into one indistinguishable 404 instead of
     * choosing between reasons and leaking which one applied.
     */
    public function resolveOrderFor(User $user, string $orderId): ?LeasybackOrder
    {
        $order = OrderRecord::find($orderId);

        if ($order === null || ! $this->allows($user, $order)) {
            return null;
        }

        return $order;
    }

    /**
     * Type-hinted against the canonical module class so callers holding either
     * shape can pass one, but the policy is asked about an `App\Models` shim:
     * AuthServiceProvider registers OrderPolicy on the shim, and Gate resolves
     * by the instance's own class and parents — a module-class instance is not
     * an instance of the shim, so asking about one silently denies.
     */
    public function allows(User $user, LeasybackOrder $order): bool
    {
        $record = $order instanceof OrderRecord ? $order : OrderRecord::find($order->id);

        if ($record === null) {
            return false;
        }

        return $user->can('pay', $record)
            && $this->isB2cOrder($record)
            && $this->isOpen($record);
    }

    /**
     * Payments belong to the B2C flow in this phase. B2B settles through
     * `b2b_order_billing` and its own completion gate, which this work does
     * not touch.
     *
     * The channel is read from the persisted vehicle by TransitionOrderStatus,
     * never from anything the request supplied — so no payload can talk a B2B
     * order onto the B2C payment path.
     */
    public function isB2cOrder(LeasybackOrder $order): bool
    {
        return ! TransitionOrderStatus::isB2bOrder($order);
    }

    /**
     * A closed order — completed, cancelled or discarded — takes no new
     * payment method and starts no new charge.
     *
     * Note this deliberately governs *storing a card and starting a charge*,
     * not settling one that already exists: a cancellation fee is by
     * definition owed on a cancelled order, so the pay page consults the
     * payment's own state rather than this.
     */
    public function isOpen(LeasybackOrder $order): bool
    {
        return ! in_array($order->order_status, OrderStatus::closedValues(), true);
    }

    /**
     * The user who owns a B2C order, derived from the persisted vehicle.
     *
     * This is what the webhook path needs and cannot get from a session, and
     * what the authenticated path uses too — so both check the mandate against
     * the same expected identity and cannot drift apart.
     */
    public function ownerOf(LeasybackOrder $order): ?User
    {
        $ownerId = $order->vehicle?->b2c_user_id;

        return $ownerId === null ? null : User::find($ownerId);
    }
}
