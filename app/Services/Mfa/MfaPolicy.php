<?php

namespace App\Services\Mfa;

use App\Enums\B2bRole;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\B2B\Services\B2bContext;

/**
 * Who has to use a second factor.
 *
 * Kept apart from the challenge machinery because it answers a rollout
 * question, not a cryptographic one, and because three callers need the same
 * answer: both login paths and the middleware that guards everything after
 * them. A disagreement between those three is what a half-enforced MFA looks
 * like.
 *
 * Ownership is read from memberships rather than from the active company, so
 * the answer does not depend on session state — it is needed before a session
 * exists.
 */
class MfaPolicy
{
    public function __construct(private readonly B2bContext $b2b) {}

    public function enabled(): bool
    {
        return (bool) config('mfa.enabled');
    }

    /**
     * Does this account have to present a second factor?
     *
     * True either because the account already enrolled — anyone who turned MFA
     * on keeps it, whatever the rollout says — or because the rollout demands
     * it of their role.
     */
    public function requires(User $user): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        if ($this->hasEnrolled($user)) {
            return true;
        }

        return $this->inRolloutScope($user);
    }

    /**
     * Must enroll but has not yet — the state that sends a user to setup
     * rather than to a code prompt.
     */
    public function mustEnroll(User $user): bool
    {
        return $this->enabled() && ! $this->hasEnrolled($user) && $this->inRolloutScope($user);
    }

    /**
     * Enrollment is complete only once a code has been proved. A secret with
     * no confirmation is an abandoned setup, not a second factor.
     */
    public function hasEnrolled(User $user): bool
    {
        return $user->mfa_confirmed_at !== null && $user->mfa_method !== null;
    }

    private function inRolloutScope(User $user): bool
    {
        if ((bool) config('mfa.required')) {
            return true;
        }

        $scope = (array) config('mfa.required_for');

        if (in_array('admin', $scope, true) && $user->user_type === UserType::Admin) {
            return true;
        }

        return in_array('b2b_owner', $scope, true) && $this->ownsACompany($user);
    }

    private function ownsACompany(User $user): bool
    {
        // Checked before the lookup because this runs in middleware on every
        // authenticated request: only a company user can own a company, so
        // every private customer and workshop is answered without a query.
        if ($user->user_type !== UserType::Firmenkunde) {
            return false;
        }

        foreach ($this->b2b->memberships($user) as $membership) {
            if ($membership->role === B2bRole::Owner) {
                return true;
            }
        }

        return false;
    }
}
