<?php

namespace App\Http\Controllers\B2b;

use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\B2b\InviteMemberRequest;
use App\Modules\UserProfile\B2B\Data\B2bMembership;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\B2B\Services\B2bInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Issuing, resending and revoking invitations. All three are reached only
 * through `b2b.can:members.manage`, and every one of them is scoped to the
 * caller's own active company — an invitation id from another company simply
 * does not resolve.
 */
class InvitationController extends Controller
{
    use HandlesServiceValidationErrors;

    public function __construct(
        private readonly B2bContext $context,
        private readonly B2bInvitationService $invitations,
    ) {}

    public function store(InviteMemberRequest $request): RedirectResponse
    {
        $membership = $this->membership($request);

        return $this->withServiceErrorHandling(
            'invitation',
            fn () => $this->invitations->invite(
                $membership,
                $request->user(),
                $request->email(),
                $request->role(),
                $request->permissions(),
                $request->vehicleScope(),
            )
        ) ?? back()->with('success', 'Einladung wurde versendet.');
    }

    public function resend(Request $request, string $invitationId): RedirectResponse
    {
        $membership = $this->membership($request);

        return $this->withServiceErrorHandling(
            'invitation',
            fn () => $this->invitations->resend($membership, $invitationId, $request->user())
        ) ?? back()->with('success', 'Einladung wurde erneut versendet.');
    }

    public function destroy(Request $request, string $invitationId): RedirectResponse
    {
        $membership = $this->membership($request);

        return $this->withServiceErrorHandling(
            'invitation',
            fn () => $this->invitations->revoke($membership, $invitationId)
        ) ?? back()->with('success', 'Einladung wurde zurückgezogen.');
    }

    private function membership(Request $request): B2bMembership
    {
        $membership = $this->context->activeMembership($request->user());

        abort_if($membership === null, 403, 'Sie gehören derzeit zu keinem Unternehmen.');

        return $membership;
    }
}
