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
