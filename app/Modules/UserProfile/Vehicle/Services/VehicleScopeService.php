<?php

namespace App\Modules\UserProfile\Vehicle\Services;

use App\Models\B2B;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class VehicleScopeService
{
    /**
     * Resolve the owner UUID for B2B or B2C based on user type.
     */
    public function resolveOwnerId(User $user): ?string
    {
        return match ($user->user_type->value) {
            'Firmenkunde' => $this->getB2bIdForUser($user->id),
            'Privatkunde' => (string) $user->id,
            'Admin' => null, // Admin has no owner scope
            default => null,
        };
    }

    /**
     * Get the b2b_id linked to a user.
     */
    public function getB2bIdForUser(int $userId): ?string
    {
        return DB::table('user_b2b')
            ->where('user_id', $userId)
            ->value('b2b_id');
    }

    /**
     * Scope a vehicle query based on user access.
     */
    public function scopeQuery(Builder $query, User $user): Builder
    {
        return match ($user->user_type->value) {
            'Admin' => $query, // no filtering
            'Firmenkunde' => $query->where(function ($q) use ($user) {
                $b2bId = $this->getB2bIdForUser($user->id);
                $q->where('vehicle_belongs', 'B2B')
                  ->where('b2b_id', $b2bId);
            }),
            'Privatkunde' => $query->where(function ($q) use ($user) {
                $q->where('vehicle_belongs', 'B2C')
                  ->where('b2c_user_id', $user->id);
            }),
            default => $query->whereRaw('1 = 0'), // deny
        };
    }

    /**
     * Fetch a vehicle with scope-based access check.
     * Returns null if the user doesn't have access.
     */
    public function findVehicleWithAccess(string $vehicleId, User $user): ?Vehicle
    {
        $query = Vehicle::where('vehicle_id', $vehicleId);
        $this->scopeQuery($query, $user);

        return $query->first();
    }
}
