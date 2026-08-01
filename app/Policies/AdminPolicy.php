<?php

namespace App\Policies;

use App\Models\User;

/**
 * The single source of truth for "is this user allowed into an Admin-only
 * operational endpoint" — replaces ~20 scattered
 * `$user->user_type->value !== 'Admin'` checks previously duplicated across
 * AdminController, TimController, and OfferController.
 *
 * Not registered via Gate::policy() (there's no single model these actions
 * belong to — they span dashboard stats, cross-customer listings, status
 * toggles, and TIM sync) — each ability is registered individually as a
 * named Gate in AuthServiceProvider instead. Kept as distinctly named,
 * identical-logic abilities (matching docs/B2C_ADMIN_PERMISSION_MATRIX.md's
 * "Admin operational resources" table rows) rather than one blanket
 * "isAdmin" check, following the same precedent as OrderPolicy's
 * approve/confirm/manageStatus/createStation.
 */
class AdminPolicy
{
    public function viewDashboardSummary(User $user): bool
    {
        return $user->isAdmin();
    }

    public function viewAdminListings(User $user): bool
    {
        return $user->isAdmin();
    }

    public function updateCustomerStatus(User $user): bool
    {
        return $user->isAdmin();
    }

    public function syncAppraisal(User $user): bool
    {
        return $user->isAdmin();
    }

    public function manageDekraProcess(User $user): bool
    {
        return $user->isAdmin();
    }
}
