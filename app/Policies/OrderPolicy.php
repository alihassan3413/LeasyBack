<?php

namespace App\Policies;

use App\Models\LeasybackOrder;
use App\Models\User;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;

class OrderPolicy
{
    public function __construct(private readonly VehicleScopeService $scope) {}

    public function view(User $user, LeasybackOrder $order): bool
    {
        return $user->isAdmin() || $this->scope->findVehicleWithAccess($order->vehicle_id, $user) !== null;
    }

    /**
     * Reading the order's message thread is exactly the right to see the
     * order itself — the order's customer (via VehicleScopeService, so a
     * Firmenkunde member restricted to their own vehicles stays restricted)
     * and Admin. Kept as its own ability rather than reusing view() so the
     * broadcast channel and the HTTP endpoints name what they authorize.
     */
    public function viewMessages(User $user, LeasybackOrder $order): bool
    {
        return $this->view($user, $order);
    }

    public function sendMessage(User $user, LeasybackOrder $order): bool
    {
        return $this->view($user, $order);
    }

    /**
     * Acting on this order's payments: opening a SetupIntent, confirming a
     * stored card, or paying an outstanding amount.
     *
     * Deliberately **not** granted to Admin, which is the one place this
     * policy departs from view(). Every other ability here treats Admin as a
     * superset of the customer, but entering or confirming a payment method is
     * something only the cardholder does — an Admin who could do it would be
     * storing a card the customer never authorized, and the mandate this flow
     * records would be attesting to a consent that never happened.
     *
     * Admin's legitimate payment powers — retrying a charge, re-sending a
     * payment link, marking an amount collected offline — are separate
     * abilities on the admin surface, not this one.
     */
    public function pay(User $user, LeasybackOrder $order): bool
    {
        return ! $user->isAdmin()
            && $this->scope->findVehicleWithAccess($order->vehicle_id, $user) !== null;
    }

    /**
     * Cancelling one's own order. Owner-only for the same reason: Admin
     * cancels through admin.orders.status, which is audited as an Admin
     * action and does not levy the customer cancellation fee.
     */
    public function cancel(User $user, LeasybackOrder $order): bool
    {
        return ! $user->isAdmin()
            && $this->scope->findVehicleWithAccess($order->vehicle_id, $user) !== null;
    }

    public function approve(User $user): bool
    {
        return $user->isAdmin();
    }

    public function confirm(User $user): bool
    {
        return $user->isAdmin();
    }

    public function manageStatus(User $user): bool
    {
        return $user->isAdmin();
    }

    public function createStation(User $user): bool
    {
        return $user->isAdmin();
    }
}
