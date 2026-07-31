<?php

namespace App\Policies;

use App\Models\LeasybackUserProfile;
use App\Models\User;
use App\Models\UserPreference;

/**
 * Governs the B2C/B2B/Werkstatt self-service profile domain (address,
 * contact, phone numbers, preferences) — see
 * docs/B2C_ADMIN_PERMISSION_MATRIX.md "UserProfile" table. Registered for
 * both LeasybackUserProfile and UserPreference in AuthServiceProvider, with
 * distinct ability names per model since a single method can't type-hint
 * two different classes.
 *
 * Create/update never allow an Admin bypass: the matrix is explicit that
 * Admin has no legitimate use case to edit a customer's own contact info
 * through this resource. View allows Admin to look at any profile, but no
 * controller endpoint exercises that today — it's here for the day one is
 * added, not speculative code, just a documented, testable rule.
 */
class ProfilePolicy
{
    public function viewProfile(User $user, LeasybackUserProfile $profile): bool
    {
        return $user->isAdmin() || $profile->user_id === $user->id;
    }

    public function createProfile(User $user): bool
    {
        return ! $user->isAdmin();
    }

    public function updateProfile(User $user): bool
    {
        return ! $user->isAdmin();
    }

    public function viewPreferences(User $user, UserPreference $preference): bool
    {
        return $user->isAdmin() || $preference->user_id === $user->id;
    }

    public function createPreferences(User $user): bool
    {
        return ! $user->isAdmin();
    }

    public function updatePreferences(User $user): bool
    {
        return ! $user->isAdmin();
    }
}
