<?php

namespace App\Policies;

use App\Models\LeasybackOffer;
use App\Models\User;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;

class OfferPolicy
{
    public function __construct(private readonly VehicleScopeService $scope) {}

    /**
     * The fix for the customerSelect BOLA: only the user who actually owns
     * the vehicle behind the offer's order may select it. Previously this
     * endpoint had no ownership check at all — any authenticated user could
     * select (and thereby close out every competing offer for) any order
     * by guessing its offer id.
     */
    public function select(User $user, LeasybackOffer $offer): bool
    {
        if ($user->isAdmin()) {
            return false; // matches customerList()'s existing "Admin cannot use customer offer endpoint" rule
        }

        $order = $offer->order;

        return $order !== null && $this->scope->findVehicleWithAccess($order->vehicle_id, $user) !== null;
    }

    /**
     * Rejecting is the mirror of select() and carries the same owner-only
     * rule: §10 pairs accept with reject, so the two abilities must not
     * diverge in who may exercise them.
     */
    public function reject(User $user, LeasybackOffer $offer): bool
    {
        return $this->select($user, $offer);
    }

    /**
     * Admin accepting an offer for the customer ("Im Auftrag des Kunden
     * annehmen", the v1 Admin behaviour) — a separate ability rather than
     * an exception inside select(), so the customer endpoint keeps its
     * strict owner-only rule and this stays reviewable on its own. The two
     * are told apart in the audit trail: OfferService::selectOffer() writes
     * `selected_by_admin_on_behalf` instead of `selected_by_customer`.
     */
    public function selectOnBehalf(User $user, LeasybackOffer $offer): bool
    {
        return $user->isAdmin() && $offer->order !== null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function publish(User $user): bool
    {
        return $user->isAdmin();
    }

    public function cancel(User $user): bool
    {
        return $user->isAdmin();
    }
}
