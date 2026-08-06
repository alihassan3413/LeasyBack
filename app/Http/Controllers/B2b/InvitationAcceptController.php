<?php

namespace App\Http\Controllers\B2b;

use App\Enums\B2bRole;
use App\Enums\UserType;
use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Http\Controllers\Controller;
use App\Modules\UserProfile\B2B\Data\B2bPermissionSet;
use App\Modules\UserProfile\B2B\Services\B2bInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The invitee's side of an invitation link.
 *
 * Reachable while logged out on purpose: the person being invited may not
 * have an account yet, and being bounced to a bare login page with no idea
 * what they were invited to is a dead end. The page shows what the invitation
 * grants and routes them to login or registration; only the accept action
 * itself requires authentication.
 */
class InvitationAcceptController extends Controller
{
    use HandlesServiceValidationErrors;

    public function __construct(private readonly B2bInvitationService $invitations) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = $this->invitations->findAnyByToken($token);
        $user = $request->user();

        // Unknown token, or one that has been used up. Naming which of the
        // four it is costs nothing — holding the token already proves the
        // holder received the email — and turns a dead end into an
        // instruction ("ask for a new one" vs. "you already joined").
        if ($invitation === null || ! $invitation->isPending()) {
            return Inertia::render('b2b/InvitationAccept', [
                'token' => $token,
                'invitation' => null,
                'status' => $invitation?->status() ?? 'invalid',
                'viewer' => null,
            ]);
        }

        return Inertia::render('b2b/InvitationAccept', [
            'token' => $token,
            'status' => 'pending',
            'invitation' => [
                'company_name' => $invitation->company?->company_name ?? '',
                'company_logo_url' => $invitation->company?->logo_url,
                'email' => $invitation->email,
                'role_label' => (B2bRole::tryFrom($invitation->role) ?? B2bRole::Member)->label(),
                'permissions' => B2bPermissionSet::fromRaw($invitation->permissions)->toArray(),
                'vehicle_scope' => $invitation->vehicle_scope,
                'expires_at' => $invitation->expires_at->toISOString(),
            ],
            // Everything the page needs to explain *why* the button is
            // disabled, rather than failing after the click.
            'viewer' => $user === null ? null : [
                'email' => $user->email,
                'user_type' => $user->user_type->value,
                'email_matches' => Str::lower($user->email) === Str::lower($invitation->email),
                // Private customers may join too — they keep their own account
                // and gain the company alongside it. Only account types with
                // no customer side at all cannot.
                'can_join' => in_array($user->user_type, [UserType::Firmenkunde, UserType::Privatkunde], true),
                'keeps_private_area' => $user->user_type === UserType::Privatkunde,
            ],
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitations->findAnyByToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            return back()->withErrors([
                'invitation' => match ($invitation?->status()) {
                    'accepted' => 'Diese Einladung wurde bereits angenommen.',
                    'revoked' => 'Diese Einladung wurde zurückgezogen.',
                    'expired' => 'Diese Einladung ist abgelaufen. Bitten Sie das Unternehmen um eine neue.',
                    default => 'Diese Einladung ist nicht mehr gültig.',
                },
            ]);
        }

        return $this->withServiceErrorHandling(
            'invitation',
            fn () => $this->invitations->accept($invitation, $request->user())
        ) ?? to_route('dashboard')->with('success', 'Sie sind dem Unternehmen beigetreten.');
    }
}
