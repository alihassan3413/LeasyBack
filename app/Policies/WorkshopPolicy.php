<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workshop;

/**
 * Formalizes the ownership rule WorkshopController already enforced inline
 * (authorizeOwner()) as a real, named, testable Policy — including the new
 * logo upload/delete actions.
 */
class WorkshopPolicy
{
    public function view(User $user, Workshop $workshop): bool
    {
        return $this->isOwnerOrAdmin($user, $workshop);
    }

    public function update(User $user, Workshop $workshop): bool
    {
        return $this->isOwnerOrAdmin($user, $workshop);
    }

    public function manageLogo(User $user, Workshop $workshop): bool
    {
        return $this->isOwnerOrAdmin($user, $workshop);
    }

    private function isOwnerOrAdmin(User $user, Workshop $workshop): bool
    {
        return $user->isAdmin() || (int) $workshop->user_id === (int) $user->id;
    }
}
