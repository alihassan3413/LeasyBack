<?php

namespace App\Modules\UserProfile\Vehicle\Services;

use App\Models\B2B;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\Vehicle as CanonicalVehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

    /**
     * Resolve who should receive an order/offer notification email for
     * this vehicle: the B2C owner's own email, or the B2B company's
     * contact_email (not a member's personal address — see B2BService/
     * AdminQueryService's own precedent of treating contact_email as the
     * company-level recipient). Returns null if neither resolves (an
     * orphaned vehicle, or a company with no contact_email set) — the
     * caller's job to skip sending, not fabricate a placeholder recipient.
     * This is the fix for the reference system's "hardcoded personal
     * address as the effective only recipient" flaw
     * (docs/B2C_ADMIN_MIGRATION_AUDIT.md §4.7).
     *
     * Accepts the canonical Vehicle class, not the App\Models shim every
     * other method here returns — callers pass in relation-loaded
     * instances (e.g. LeasybackOrder::vehicle()) that are always canonical,
     * and a shim instance (IS-A canonical, since the shim just extends it)
     * satisfies this type hint too, so both call shapes work.
     *
     * @return array{email: string, name: string}|null
     */
    /**
     * Every user who should be notified about this vehicle: the B2C owner, or
     * all members of the owning B2B company.
     *
     * @return Collection<int, User>
     */
    public function resolveOwnerUsers(CanonicalVehicle $vehicle): Collection
    {
        if ($vehicle->vehicle_belongs === 'B2C' && $vehicle->b2c_user_id) {
            return User::where('id', $vehicle->b2c_user_id)->get();
        }

        if ($vehicle->vehicle_belongs === 'B2B' && $vehicle->b2b_id) {
            $userIds = DB::table('user_b2b')->where('b2b_id', $vehicle->b2b_id)->pluck('user_id');

            return User::whereIn('id', $userIds)->get();
        }

        return collect();
    }

    public function resolveOwnerContact(CanonicalVehicle $vehicle): ?array
    {
        if ($vehicle->vehicle_belongs === 'B2C' && $vehicle->b2c_user_id) {
            $user = User::find($vehicle->b2c_user_id);

            return $user ? ['email' => $user->email, 'name' => $user->name] : null;
        }

        if ($vehicle->vehicle_belongs === 'B2B' && $vehicle->b2b_id) {
            $b2b = B2B::find($vehicle->b2b_id);

            return $b2b?->contact_email
                ? ['email' => $b2b->contact_email, 'name' => $b2b->company_name ?? '']
                : null;
        }

        return null;
    }
}
