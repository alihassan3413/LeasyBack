<?php

namespace App\Services\Mfa;

use App\Enums\B2bRole;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use Illuminate\Support\Facades\Log;

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

        // An account that already has a factor keeps it, whatever the rollout
        // or the exemption list says. Checked before the exemption so naming
        // someone there cannot quietly downgrade them.
        if ($this->hasEnrolled($user)) {
            return true;
        }

        if ($this->isExempt($user)) {
            return false;
        }

        return $this->inRolloutScope($user);
    }

    /**
     * Must enroll but has not yet — the state that sends a user to setup
     * rather than to a code prompt.
     */
    public function mustEnroll(User $user): bool
    {
        return $this->enabled()
            && ! $this->hasEnrolled($user)
            && ! $this->isExempt($user)
            && $this->inRolloutScope($user);
    }

    /**
     * Is this account excused from having to enrol?
     *
     * Matched on the email address, case-insensitively, against
     * `mfa.exempt_emails` — empty in production, so this is false for everyone
     * unless an environment deliberately names someone.
     *
     * Logged at notice on every hit rather than silently: an account walking
     * past a control the rest of the userbase is held to should be visible in
     * the log, not only in a config file somebody has to think to read.
     */
    public function isExempt(User $user): bool
    {
        $exempt = array_map('mb_strtolower', (array) config('mfa.exempt_emails'));

        if ($exempt === [] || ! in_array(mb_strtolower((string) $user->email), $exempt, true)) {
            return false;
        }

        Log::notice('mfa.requirement_waived', ['user_id' => $user->id]);

        return true;
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
