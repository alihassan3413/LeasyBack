<?php

namespace App\Modules\UserProfile\B2B\Services;

use App\Enums\B2bPermission;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\B2B\Data\B2bMembership;
use Illuminate\Support\Facades\DB;

/**
 * Resolves *which* company a user is currently acting as, and what they may
 * do in it.
 *
 * This exists because a user can belong to more than one B2B company. Before
 * that was possible, code could get away with
 * `DB::table('user_b2b')->where('user_id', $id)->value('b2b_id')` — with two
 * memberships that returns an arbitrary row, which for an authorization
 * boundary means "sometimes shows the wrong company's fleet". Every lookup of
 * a user's company now goes through here instead.
 *
 * It also resolves the *other* half of that question: whether the user is
 * acting as a company at all. A Privatkunde who accepts a B2B invitation keeps
 * their private account and gains a company alongside it, so `user_type` alone
 * no longer answers "is this request B2B?" — `active_b2b_id` does. See
 * effectiveUserType().
 *
 * Registered as a scoped singleton, so the membership is resolved once per
 * request no matter how many policies, controllers and services ask for it.
 */
class B2bContext
{
    /** @var array<int, B2bMembership|null> */
    private array $activeCache = [];

    /** @var array<int, list<B2bMembership>> */
    private array $membershipCache = [];

    /**
     * The company this user is acting as, or null if they belong to none —
     * or, for an account that also has a private side, if they are currently
     * acting privately.
     *
     * Falls back to the earliest membership when `active_b2b_id` is unset or
     * points at a company they have since been removed from, and persists
     * that fallback so the answer is stable across requests. That fallback is
     * deliberately skipped for accounts with a personal context: for a
     * Privatkunde `active_b2b_id = null` is a meaningful state ("acting as
     * myself"), not a missing value to be filled in.
     */
    public function activeMembership(User $user): ?B2bMembership
    {
        if (array_key_exists($user->id, $this->activeCache)) {
            return $this->activeCache[$user->id];
        }

        $memberships = $this->memberships($user);

        if ($memberships === []) {
            return $this->activeCache[$user->id] = null;
        }

        if ($user->active_b2b_id === null && $this->hasPersonalContext($user)) {
            return $this->activeCache[$user->id] = null;
        }

        $active = null;

        if ($user->active_b2b_id !== null) {
            foreach ($memberships as $membership) {
                if ($membership->b2bId === $user->active_b2b_id) {
                    $active = $membership;
                    break;
                }
            }
        }

        if ($active === null) {
            // Points at a company they no longer belong to. An account with a
            // private side falls back to that rather than being dropped into
            // an arbitrary other company.
            if ($this->hasPersonalContext($user)) {
                $this->persistActive($user, null);

                return $this->activeCache[$user->id] = null;
            }

            $active = $memberships[0];
            $this->persistActive($user, $active->b2bId);
        }

        return $this->activeCache[$user->id] = $active;
    }

    /**
     * Whether this account has a meaningful non-company side to switch back
     * to. A Privatkunde does — their own vehicles and orders live on the user
     * row itself and are untouched by joining a company. A Firmenkunde does
     * not: everything they own belongs to a company.
     */
    public function hasPersonalContext(User $user): bool
    {
        return $user->user_type === UserType::Privatkunde;
    }

    /**
     * The account type this request should be treated as.
     *
     * The whole application used to branch on `user_type` to decide whether a
     * request is B2B or B2C. That stopped being sufficient once a Privatkunde
     * could join a company: the same account is B2C while acting privately and
     * B2B while acting as a company. Every such branch now asks this instead,
     * so the answer is decided in one place.
     *
     * For every account that existed before dual context this returns exactly
     * `user_type`, so no previous behaviour changes.
     */
    public function effectiveUserType(User $user): UserType
    {
        // Admins have no memberships and their own area; skip the lookup.
        if ($user->user_type === UserType::Admin) {
            return UserType::Admin;
        }

        return $this->activeMembership($user) !== null
            ? UserType::Firmenkunde
            : $user->user_type;
    }

    /**
     * True when the user is currently acting as one of their companies.
     */
    public function actsAsCompany(User $user): bool
    {
        return $this->effectiveUserType($user) === UserType::Firmenkunde;
    }

    /**
     * Every company this user belongs to, oldest membership first.
     *
     * @return list<B2bMembership>
     */
    public function memberships(User $user): array
    {
        if (array_key_exists($user->id, $this->membershipCache)) {
            return $this->membershipCache[$user->id];
        }

        $rows = DB::table('user_b2b as ub')
            ->join('b2b as b', 'b.b2b_id', '=', 'ub.b2b_id')
            ->where('ub.user_id', $user->id)
            ->where('ub.status', 'active')
            ->orderBy('ub.created_at')
            ->orderBy('ub.b2b_id')
            ->get(['ub.user_id', 'ub.b2b_id', 'ub.role', 'ub.permissions', 'ub.vehicle_scope',
                'b.company_name', 'b.logo_url']);

        return $this->membershipCache[$user->id] = $rows
            ->map(fn (object $row) => B2bMembership::fromRow($row))
            ->all();
    }

    /**
     * Convenience for the many call sites that only need the company id.
     */
    public function activeCompanyId(User $user): ?string
    {
        return $this->activeMembership($user)?->b2bId;
    }

    /**
     * Same, addressed by user id — for the handful of legacy call sites that
     * only ever had an id to work with.
     */
    public function activeCompanyIdForUserId(int|string $userId): ?string
    {
        $user = User::find($userId);

        return $user ? $this->activeCompanyId($user) : null;
    }

    public function can(User $user, B2bPermission $permission): bool
    {
        return $this->activeMembership($user)?->can($permission) ?? false;
    }

    /**
     * Switch the acting company. Returns false when the user is not an active
     * member of the target — the caller must not treat that as success.
     */
    public function switchTo(User $user, string $b2bId): bool
    {
        foreach ($this->memberships($user) as $membership) {
            if ($membership->b2bId === $b2bId) {
                $this->persistActive($user, $b2bId);
                $this->activeCache[$user->id] = $membership;

                return true;
            }
        }

        return false;
    }

    /**
     * Leave company context and act privately again. Returns false for
     * accounts that have no private side to return to — a Firmenkunde must
     * always be acting as one of their companies.
     */
    public function switchToPersonal(User $user): bool
    {
        if (! $this->hasPersonalContext($user)) {
            return false;
        }

        $this->persistActive($user, null);
        $this->activeCache[$user->id] = null;

        return true;
    }

    /**
     * Drop memoised state after a membership change, so the rest of the
     * request sees the new permissions rather than the ones it started with.
     */
    public function forget(User|int $user): void
    {
        $id = $user instanceof User ? $user->id : $user;

        unset($this->activeCache[$id], $this->membershipCache[$id]);
    }

    private function persistActive(User $user, ?string $b2bId): void
    {
        $user->forceFill(['active_b2b_id' => $b2bId])->saveQuietly();
    }
}
