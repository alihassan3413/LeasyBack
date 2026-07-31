<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks any already-authenticated request from a deactivated account, even
 * if it was authenticated before the account was deactivated — without this,
 * is_active only mattered at the moment of login/session-start and a
 * previously issued Sanctum token or an already-running session would keep
 * working indefinitely.
 *
 * Handles both authentication mechanisms this app uses:
 * - Sanctum bearer token (API): revokes the token, returns a JSON 403.
 * - Web session (Inertia): logs the guard out, invalidates the session,
 *   redirects to login.
 *
 * Deliberately more specific ("account deactivated") than the generic
 * "Invalid credentials." used at login: the caller already proved ownership
 * by presenting a valid token/session, so there is no enumeration concern in
 * confirming why access is denied.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_active) {
            return $next($request);
        }

        if ($user->currentAccessToken()) {
            $user->currentAccessToken()->delete();

            return response()->json([
                'ok' => false,
                'data' => null,
                'message' => 'This account has been deactivated.',
            ], 403);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // German: this flows into the Vue Login page's `status` prop, which
        // is otherwise entirely German (see docs/AUTH_FRONTEND_IMPLEMENTATION_PLAN.md).
        // The Sanctum/API branch above stays English — that's the external
        // API's own error contract (see docs/AUTH_MODULE.md), a separate surface.
        return redirect()->route('login')->with('status', 'Dieser Account wurde deaktiviert.');
    }
}
