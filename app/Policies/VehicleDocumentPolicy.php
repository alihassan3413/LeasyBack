<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleDocument;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;

/**
 * Customer-uploaded vehicle documents (Leasingvertrag, vorschaden, gutachten,
 * Sonstiges). Every action is gated on owning the parent vehicle — Admin
 * always passes, everyone else must own the vehicle via
 * VehicleScopeService (the same scoping Vehicle itself uses, not a
 * second, divergent ownership check).
 */
class VehicleDocumentPolicy
{
    public function __construct(private readonly VehicleScopeService $scope) {}

    /**
     * @param  string  $vehicleId  Passed explicitly since there's no document yet to inspect.
     */
    public function viewAny(User $user, string $vehicleId): bool
    {
        return $this->ownsVehicle($user, $vehicleId);
    }

    public function view(User $user, VehicleDocument $document): bool
    {
        return $this->ownsVehicle($user, $document->vehicle_id);
    }

    /**
     * @param  string  $vehicleId  Passed explicitly since there's no document yet to inspect.
     */
    public function create(User $user, string $vehicleId): bool
    {
        return $this->ownsVehicle($user, $vehicleId);
    }

    public function delete(User $user, VehicleDocument $document): bool
    {
        return $this->ownsVehicle($user, $document->vehicle_id);
    }

    private function ownsVehicle(User $user, string $vehicleId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->scope->findVehicleWithAccess($vehicleId, $user) !== null;
    }
}
