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
