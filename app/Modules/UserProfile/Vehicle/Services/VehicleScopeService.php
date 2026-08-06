<?php

namespace App\Modules\UserProfile\Vehicle\Services;

use App\Enums\B2bPermission;
use App\Models\B2B;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\Vehicle\Models\Vehicle as CanonicalVehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VehicleScopeService
{
    public function __construct(private readonly B2bContext $b2bContext) {}

    /**
     * Resolve the owner UUID for B2B or B2C based on the context the user is
     * currently acting in.
     *
     * Keyed on B2bContext::effectiveUserType() rather than the raw `user_type`
     * column: a Privatkunde who joined a company is B2C while acting privately
     * and B2B while acting as that company, and the owner id has to follow.
     */
    public function resolveOwnerId(User $user): ?string
    {
        return match ($this->b2bContext->effectiveUserType($user)->value) {
            'Firmenkunde' => $this->getB2bIdForUser($user->id),
            'Privatkunde' => (string) $user->id,
            'Admin' => null, // Admin has no owner scope
            default => null,
        };
    }

    /**
     * Get the b2b_id of the company this user is currently acting as.
     *
     * Goes through B2bContext rather than reading `user_b2b` directly: a user
     * can belong to several companies, and picking whichever row the database
     * returns first would silently leak one company's fleet into another's.
     */
    public function getB2bIdForUser(int $userId): ?string
    {
        return $this->b2bContext->activeCompanyIdForUserId($userId);
    }

    /**
     * The `created_by_user_id` a viewer must be narrowed to, or null when they
     * may see the whole company fleet.
     *
     * This is the single definition of the member-level half of vehicle
     * access. It exists because the rule has two applications: scopeQuery()
     * below, which builds an Eloquent constraint for policies and detail
     * lookups, and VehicleService's dashboard listing, which is a query-builder
     * statement and cannot take an Eloquent scope. Phase 17 finding 1 was
     * exactly that second application being missing — so the decision now lives
     * in one place and both callers ask it rather than re-deriving it.
     *
     * Accounts not acting as a company are never narrowed: a Privatkunde's own
     * filter is `b2c_user_id`, and an Admin sees everything.
     */
    public function ownVehicleRestrictionFor(User $user): ?int
    {
        return $this->b2bContext->activeMembership($user)?->seesOwnVehiclesOnly()
            ? $user->id
            : null;
    }

    /**
     * Scope a vehicle query based on user access.
     *
     * For a Firmenkunde this applies both halves of their access: the company
     * they are acting as, and — for a member restricted to
     * B2bVehicleScope::Own — only the vehicles they registered themselves.
     * Every policy, listing and detail lookup in the app funnels through here
     * or through scopeQuery()'s callers, so the restriction cannot be
     * side-stepped by reaching a vehicle from a different direction.
     */
    public function scopeQuery(Builder $query, User $user): Builder
    {
        return match ($this->b2bContext->effectiveUserType($user)->value) {
            'Admin' => $query, // no filtering
            'Firmenkunde' => $query->where(function ($q) use ($user) {
                $membership = $this->b2bContext->activeMembership($user);

                if (! $membership || ! $membership->can(B2bPermission::ViewVehicles)) {
                    $q->whereRaw('1 = 0');

                    return;
                }

                $q->where('vehicle_belongs', 'B2B')
                    ->where('b2b_id', $membership->b2bId);

                $restrictedTo = $this->ownVehicleRestrictionFor($user);

                if ($restrictedTo !== null) {
                    $q->where('created_by_user_id', $restrictedTo);
                }
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
