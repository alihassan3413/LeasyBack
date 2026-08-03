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
