<?php

namespace App\Services\Mfa;

use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Whether this request is an admin acting as a customer through a live
 * impersonation — the one case in which the customer's own second factor is
 * not asked for. The customer is not the one signing in; the admin is, and the
 * admin's factor is what this session already carries.
 *
 * Decided entirely from server-side session state that only
 * ImpersonationController writes, and re-checked on every request:
 *
 * - the session names both the impersonating admin and the impersonated user,
 *   and the impersonated user is the one authenticated right now;
 * - that admin still exists, is still an admin and is still active;
 * - when MFA applies to the admin, the admin passed it in this very session
 *   before taking over (mfa.verified_user_id survives the session regenerate
 *   that starts the impersonation).
 *
 * Nothing a client sends can satisfy this: session contents are server-side,
 * and a session merely carrying an id fails the admin checks above. Ending the
 * impersonation removes the keys, so the customer's normal rules apply again on
 * the very next request.
 */
class ImpersonationMfaExemption
{
    public function __construct(private readonly MfaPolicy $policy) {}

    public function applies(Request $request, User $user): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $session = $request->session();
        $adminId = $session->get(ImpersonationController::SESSION_KEY);

        if ($adminId === null || (int) $session->get(ImpersonationController::TARGET_SESSION_KEY) !== (int) $user->id) {
            return false;
        }

        $admin = User::find($adminId);

        if ($admin === null || $admin->is($user) || ! $admin->isAdmin() || ! $admin->is_active) {
            return false;
        }

        return ! $this->policy->requires($admin)
            || (int) $session->get('mfa.verified_user_id') === (int) $admin->id;
    }
}
