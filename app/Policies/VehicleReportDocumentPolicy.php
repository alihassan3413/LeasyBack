<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleReportDocument;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;

/**
 * Admin-managed report/invoice documents (transferred from a TIM assessment,
 * or uploaded directly). Managing them (create/upload/publish/delete) is
 * Admin-only; the vehicle's own owner may only *view* a document once it's
 * been explicitly published — mirrors the customer dashboard's read-only
 * "Gutachten/Rechnung" cards.
 */
class VehicleReportDocumentPolicy
{
    public function __construct(private readonly VehicleScopeService $scope) {}

    public function view(User $user, VehicleReportDocument $document): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $document->published) {
            return false;
        }

        return $this->scope->findVehicleWithAccess($document->vehicle_id, $user) !== null;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function publish(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user): bool
    {
        return $user->isAdmin();
    }
}
