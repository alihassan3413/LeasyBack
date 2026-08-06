<?php

namespace App\Modules\UserProfile\B2B\Services;

use App\Enums\B2bPermission;
use App\Enums\B2bRole;
use App\Enums\B2bVehicleScope;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\B2B\Data\B2bMembership;
use App\Modules\UserProfile\B2B\Data\B2bPermissionSet;
use App\Modules\UserProfile\B2B\Models\B2bInvitation;
use App\Notifications\B2bInvitationNotification;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Issues, revokes and redeems invitations to a B2B company.
 *
 * The access an invitee ends up with is fixed at invitation time by the
 * inviting owner and carried on the invitation row — acceptance copies it
 * verbatim. Nothing the accepting user sends influences their own role,
 * permissions or vehicle scope.
 */
class B2bInvitationService
{
    private const EXPIRY_DAYS = 14;

    public function __construct(
        private readonly B2bMembershipService $memberships,
        private readonly B2bContext $context,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listForCompany(string $b2bId): array
    {
        return B2bInvitation::query()
            ->with('invitedBy:id,email')
            ->where('b2b_id', $b2bId)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (B2bInvitation $invitation) => [
                'invitation_id' => $invitation->invitation_id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'role_label' => (B2bRole::tryFrom($invitation->role) ?? B2bRole::Member)->label(),
                'permissions' => B2bPermissionSet::fromRaw($invitation->permissions)->toArray(),
                'vehicle_scope' => $invitation->vehicle_scope,
                'status' => $invitation->status(),
                'expires_at' => $invitation->expires_at->toISOString(),
                'created_at' => $invitation->created_at->toISOString(),
                'invited_by_email' => $invitation->invitedBy?->email,
            ])
            ->all();
    }

    /**
     * Create (or refresh) an invitation and email it.
     *
     * Re-inviting an address that already has an open invitation replaces it
     * rather than creating a second one — otherwise revoking "the" invitation
     * would leave older, still-valid links working.
     */
    public function invite(
        B2bMembership $actor,
        User $inviter,
        string $email,
        B2bRole $role,
        B2bPermissionSet $permissions,
        B2bVehicleScope $scope,
    ): B2bInvitation {
        $email = Str::lower(trim($email));

        if ($role === B2bRole::Owner && ! $actor->isOwner()) {
            $this->fail(403, 'Nur Inhaber können weitere Inhaber einladen.');
        }

        $this->assertNotAlreadyMember($actor->b2bId, $email);

        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($actor, $inviter, $email, $role, $permissions, $scope, $token) {
            B2bInvitation::query()
                ->where('b2b_id', $actor->b2bId)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return B2bInvitation::create([
                'b2b_id' => $actor->b2bId,
                'email' => $email,
                'role' => $role->value,
                'permissions' => $permissions->toArray(),
                'vehicle_scope' => $role === B2bRole::Owner ? B2bVehicleScope::All->value : $scope->value,
                'token_hash' => hash('sha256', $token),
                'invited_by_user_id' => $inviter->id,
                'expires_at' => now()->addDays(self::EXPIRY_DAYS),
            ]);
        });

        $this->send($invitation, $actor->companyName, $inviter, $token);

        return $invitation;
    }

    /**
     * Issue a fresh token for an existing open invitation and email it again.
     */
    public function resend(B2bMembership $actor, string $invitationId, User $inviter): B2bInvitation
    {
        $invitation = $this->openInvitationForCompany($actor->b2bId, $invitationId);
        $token = Str::random(64);

        $invitation->update([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::EXPIRY_DAYS),
        ]);

        $this->send($invitation, $actor->companyName, $inviter, $token);

        return $invitation;
    }

    public function revoke(B2bMembership $actor, string $invitationId): void
    {
        $this->openInvitationForCompany($actor->b2bId, $invitationId)
            ->update(['revoked_at' => now()]);
    }

    /**
     * Look up a *usable* invitation by its plaintext token. Returns null for
     * anything that cannot be accepted — unknown, revoked, already accepted or
     * expired — so no caller can accidentally act on a dead invitation.
     */
    public function findByToken(string $token): ?B2bInvitation
    {
        $invitation = $this->findAnyByToken($token);

        return $invitation?->isPending() ? $invitation : null;
    }

    /**
     * The invitation behind this token whatever state it is in, so the accept
     * page can say *why* a link no longer works instead of a flat "invalid".
     *
     * Telling the token holder that their own invitation expired leaks
     * nothing: holding the token already proves they received the email.
     * Unknown tokens still resolve to null, so guessing one reveals nothing.
     */
    public function findAnyByToken(string $token): ?B2bInvitation
    {
        return B2bInvitation::query()
            ->with('company:b2b_id,company_name,logo_url')
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }

    /**
     * Redeem an invitation for the authenticated user.
     *
     * The email is checked against the invitation: a link forwarded to a
     * different person must not let that person into the company.
     *
     * An existing Privatkunde is joined as-is — no second account is created
     * and `user_type` is left alone, so their own vehicles, orders and profile
     * survive untouched and they can switch back to them at any time (see
     * B2bContext::switchToPersonal). Only account types that have no customer
     * side at all are refused.
     */
    public function accept(B2bInvitation $invitation, User $user): void
    {
        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            $this->fail(403, 'Diese Einladung wurde an eine andere E-Mail-Adresse gesendet.');
        }

        if (! in_array($user->user_type, [UserType::Firmenkunde, UserType::Privatkunde], true)) {
            $this->fail(422, 'Dieses Konto kann keinem Unternehmen beitreten.');
        }

        DB::transaction(function () use ($invitation, $user) {
            $fresh = B2bInvitation::query()
                ->where('invitation_id', $invitation->invitation_id)
                ->lockForUpdate()
                ->first();

            if (! $fresh || ! $fresh->isPending()) {
                $this->fail(410, 'Diese Einladung ist nicht mehr gültig.');
            }

            $alreadyMember = DB::table('user_b2b')
                ->where('b2b_id', $fresh->b2b_id)
                ->where('user_id', $user->id)
                ->exists();

            if (! $alreadyMember) {
                $this->memberships->addMember(
                    userId: $user->id,
                    b2bId: $fresh->b2b_id,
                    role: B2bRole::tryFrom($fresh->role) ?? B2bRole::Member,
                    permissions: B2bPermissionSet::fromRaw($fresh->permissions),
                    scope: B2bVehicleScope::tryFrom($fresh->vehicle_scope) ?? B2bVehicleScope::All,
                    invitedByUserId: $fresh->invited_by_user_id,
                );
            }

            $fresh->update([
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->id,
            ]);

            // Land them in the company they just joined rather than whichever
            // one they happened to be acting as before.
            $this->context->forget($user);
            $this->context->switchTo($user->refresh(), $fresh->b2b_id);
        });
    }

    private function send(B2bInvitation $invitation, string $companyName, User $inviter, string $token): void
    {
        $role = B2bRole::tryFrom($invitation->role) ?? B2bRole::Member;

        // An owner holds everything implicitly and is stored without an
        // explicit list — spelling out all twelve lines would be noise, so the
        // role alone carries the meaning for them.
        $permissions = $role === B2bRole::Owner
            ? B2bPermissionSet::all()
            : B2bPermissionSet::fromRaw($invitation->permissions);

        Notification::route('mail', $invitation->email)->notify(new B2bInvitationNotification(
            companyName: $companyName,
            acceptUrl: route('b2b.invitations.show', ['token' => $token]),
            invitedByName: $inviter->name ?: $inviter->email,
            roleLabel: $role->label(),
            expiresInDays: self::EXPIRY_DAYS,
            invitedEmail: $invitation->email,
            expiresAt: $invitation->expires_at,
            vehicleScope: B2bVehicleScope::tryFrom($invitation->vehicle_scope) ?? B2bVehicleScope::All,
            permissionLabels: $role === B2bRole::Owner
                ? []
                : array_map(
                    fn (string $value) => B2bPermission::from($value)->label(),
                    $permissions->toArray(),
                ),
        ));
    }

    private function openInvitationForCompany(string $b2bId, string $invitationId): B2bInvitation
    {
        $invitation = B2bInvitation::query()
            ->where('b2b_id', $b2bId)
            ->where('invitation_id', $invitationId)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->first();

        if (! $invitation) {
            $this->fail(404, 'Diese Einladung existiert nicht mehr.');
        }

        return $invitation;
    }

    private function assertNotAlreadyMember(string $b2bId, string $email): void
    {
        $isMember = DB::table('user_b2b as ub')
            ->join('users as u', 'u.id', '=', 'ub.user_id')
            ->where('ub.b2b_id', $b2bId)
            ->whereRaw('lower(u.email) = ?', [$email])
            ->exists();

        if ($isMember) {
            $this->fail(422, 'Diese Person gehört bereits zu Ihrem Unternehmen.');
        }
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }
}
