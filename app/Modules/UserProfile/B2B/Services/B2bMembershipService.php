<?php

namespace App\Modules\UserProfile\B2B\Services;

use App\Enums\B2bRole;
use App\Enums\B2bVehicleScope;
use App\Models\User;
use App\Modules\UserProfile\B2B\Data\B2bMembership;
use App\Modules\UserProfile\B2B\Data\B2bPermissionSet;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Reads and mutates company memberships.
 *
 * Two invariants are enforced here rather than at the controller, because
 * they must hold no matter which entry point (web, API, console) does the
 * writing:
 *
 *  1. A company always keeps at least one owner. The last owner cannot be
 *     demoted or removed — otherwise the company becomes permanently
 *     unmanageable, with no one able to invite a replacement.
 *  2. Only an owner may create another owner, or touch an existing owner's
 *     membership. A member holding ManageMembers can administer members, but
 *     cannot promote themselves past the ceiling they were given.
 */
class B2bMembershipService
{
    public function __construct(private readonly B2bContext $context) {}

    /**
     * Everyone in the company, owners first, with per-member activity counts
     * so the owner can see at a glance who is actually using the account.
     *
     * @return list<array<string, mixed>>
     */
    public function listMembers(string $b2bId): array
    {
        $rows = DB::table('user_b2b as ub')
            ->join('users as u', 'u.id', '=', 'ub.user_id')
            ->leftJoin('users as inviter', 'inviter.id', '=', 'ub.invited_by_user_id')
            ->where('ub.b2b_id', $b2bId)
            ->where('ub.status', 'active')
            ->orderByRaw("CASE WHEN ub.role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('ub.created_at')
            ->get([
                'ub.user_id', 'ub.role', 'ub.permissions', 'ub.vehicle_scope', 'ub.joined_at',
                'u.name', 'u.email', 'u.is_active', 'inviter.email as invited_by_email',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $userIds = $rows->pluck('user_id')->all();

        $vehicleCounts = DB::table('vehicles')
            ->where('b2b_id', $b2bId)
            ->whereIn('created_by_user_id', $userIds)
            ->groupBy('created_by_user_id')
            ->pluck(DB::raw('count(*)'), 'created_by_user_id');

        $orderCounts = DB::table('leasyback_orders as lo')
            ->join('vehicles as v', 'v.vehicle_id', '=', 'lo.vehicle_id')
            ->where('v.b2b_id', $b2bId)
            ->whereIn('lo.created_by_user_id', $userIds)
            ->groupBy('lo.created_by_user_id')
            ->pluck(DB::raw('count(*)'), 'lo.created_by_user_id');

        return $rows->map(function (object $row) use ($vehicleCounts, $orderCounts) {
            $role = B2bRole::tryFrom((string) $row->role) ?? B2bRole::Member;
            $isOwner = $role === B2bRole::Owner;

            return [
                'user_id' => (int) $row->user_id,
                'name' => $row->name,
                'email' => $row->email,
                'is_active' => (bool) $row->is_active,
                'role' => $role->value,
                'role_label' => $role->label(),
                'vehicle_scope' => $isOwner
                    ? B2bVehicleScope::All->value
                    : (B2bVehicleScope::tryFrom((string) $row->vehicle_scope)?->value ?? B2bVehicleScope::All->value),
                'permissions' => $isOwner
                    ? B2bPermissionSet::all()->toArray()
                    : B2bPermissionSet::fromRaw($this->decodeJson($row->permissions))->toArray(),
                'joined_at' => $row->joined_at ? Carbon::parse($row->joined_at)->toISOString() : null,
                'invited_by_email' => $row->invited_by_email,
                'vehicle_count' => (int) ($vehicleCounts[$row->user_id] ?? 0),
                'order_count' => (int) ($orderCounts[$row->user_id] ?? 0),
            ];
        })->all();
    }

    /**
     * Add a user to a company. Used by invitation acceptance; never exposed
     * as a direct endpoint, so nobody can add themselves to a company they
     * were not invited to.
     */
    public function addMember(
        int $userId,
        string $b2bId,
        B2bRole $role,
        B2bPermissionSet $permissions,
        B2bVehicleScope $scope,
        ?int $invitedByUserId = null,
    ): void {
        DB::table('user_b2b')->insert([
            'user_id' => $userId,
            'b2b_id' => $b2bId,
            'role' => $role->value,
            'permissions' => json_encode($permissions->toArray()),
            'vehicle_scope' => $scope->value,
            'status' => 'active',
            'invited_by_user_id' => $invitedByUserId,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->context->forget($userId);
    }

    /**
     * Change a member's role, permissions and vehicle scope.
     */
    public function updateMember(
        B2bMembership $actor,
        int $targetUserId,
        B2bRole $role,
        B2bPermissionSet $permissions,
        B2bVehicleScope $scope,
    ): void {
        DB::transaction(function () use ($actor, $targetUserId, $role, $permissions, $scope) {
            $target = $this->lockedMembership($actor->b2bId, $targetUserId);
            $targetRole = B2bRole::tryFrom((string) $target->role) ?? B2bRole::Member;

            $this->assertMayAdminister($actor, $targetRole, $role);

            if ($targetRole === B2bRole::Owner && $role !== B2bRole::Owner) {
                $this->assertNotLastOwner($actor->b2bId, $targetUserId);
            }

            DB::table('user_b2b')
                ->where('b2b_id', $actor->b2bId)
                ->where('user_id', $targetUserId)
                ->update([
                    'role' => $role->value,
                    // An owner's stored permissions are meaningless (they hold
                    // everything implicitly) but writing the full set keeps the
                    // row honest if they are later demoted mid-transaction.
                    'permissions' => json_encode(
                        $role === B2bRole::Owner ? B2bPermissionSet::all()->toArray() : $permissions->toArray()
                    ),
                    'vehicle_scope' => $role === B2bRole::Owner ? B2bVehicleScope::All->value : $scope->value,
                    'updated_at' => now(),
                ]);

            $this->context->forget($targetUserId);
        });
    }

    /**
     * Remove someone from the company. Their vehicles stay with the company —
     * they are company property, not the member's.
     */
    public function removeMember(B2bMembership $actor, int $targetUserId): void
    {
        DB::transaction(function () use ($actor, $targetUserId) {
            $target = $this->lockedMembership($actor->b2bId, $targetUserId);
            $targetRole = B2bRole::tryFrom((string) $target->role) ?? B2bRole::Member;

            if ($targetRole === B2bRole::Owner) {
                if (! $actor->isOwner()) {
                    $this->fail(403, 'Nur Inhaber können andere Inhaber entfernen.');
                }

                $this->assertNotLastOwner($actor->b2bId, $targetUserId);
            }

            DB::table('user_b2b')
                ->where('b2b_id', $actor->b2bId)
                ->where('user_id', $targetUserId)
                ->delete();

            // Clear their acting company if it was this one, so their next
            // request falls back to another membership instead of a company
            // they no longer belong to.
            DB::table('users')
                ->where('id', $targetUserId)
                ->where('active_b2b_id', $actor->b2bId)
                ->update(['active_b2b_id' => null]);

            $this->context->forget($targetUserId);
        });
    }

    /**
     * Leaving a company on your own initiative. Same last-owner guard.
     */
    public function leave(User $user, string $b2bId): void
    {
        DB::transaction(function () use ($user, $b2bId) {
            $membership = $this->lockedMembership($b2bId, $user->id);

            if ((B2bRole::tryFrom((string) $membership->role) ?? B2bRole::Member) === B2bRole::Owner) {
                $this->assertNotLastOwner($b2bId, $user->id);
            }

            DB::table('user_b2b')->where('b2b_id', $b2bId)->where('user_id', $user->id)->delete();

            if ($user->active_b2b_id === $b2bId) {
                $user->forceFill(['active_b2b_id' => null])->saveQuietly();
            }

            $this->context->forget($user);
        });
    }

    private function lockedMembership(string $b2bId, int $userId): object
    {
        $row = DB::table('user_b2b')
            ->where('b2b_id', $b2bId)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first(['role', 'status']);

        if (! $row) {
            $this->fail(404, 'Dieses Mitglied gehört nicht zu Ihrem Unternehmen.');
        }

        return $row;
    }

    /**
     * A member with ManageMembers may administer other members, but may not
     * touch owners and may not mint new ones — only an owner can do that.
     */
    private function assertMayAdminister(B2bMembership $actor, B2bRole $targetRole, B2bRole $newRole): void
    {
        if ($actor->isOwner()) {
            return;
        }

        if ($targetRole === B2bRole::Owner) {
            $this->fail(403, 'Nur Inhaber können die Rechte anderer Inhaber ändern.');
        }

        if ($newRole === B2bRole::Owner) {
            $this->fail(403, 'Nur Inhaber können weitere Inhaber ernennen.');
        }
    }

    private function assertNotLastOwner(string $b2bId, int $exceptUserId): void
    {
        $remaining = DB::table('user_b2b')
            ->where('b2b_id', $b2bId)
            ->where('role', B2bRole::Owner->value)
            ->where('status', 'active')
            ->where('user_id', '!=', $exceptUserId)
            ->count();

        if ($remaining === 0) {
            $this->fail(422, 'Das Unternehmen muss mindestens einen Inhaber behalten.');
        }
    }

    /**
     * @return array<mixed>
     */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        return is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }
}
