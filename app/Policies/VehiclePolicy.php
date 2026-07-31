<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;

class VehiclePolicy
{
    public function __construct(private readonly VehicleScopeService $scope) {}

    public function view(User $user, Vehicle $vehicle): bool
    {
        return $this->ownsVehicle($user, $vehicle);
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $this->ownsVehicle($user, $vehicle);
    }

    private function ownsVehicle(User $user, Vehicle $vehicle): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->scope->findVehicleWithAccess($vehicle->vehicle_id, $user) !== null;
    }
}
