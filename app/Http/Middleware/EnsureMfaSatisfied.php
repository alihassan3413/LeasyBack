<?php

namespace App\Http\Middleware;

use App\Services\Mfa\MfaPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses an authenticated request that never presented a second factor.
 *
 * The login paths already decline to issue a session or a token to anyone who
 * owes MFA, so in normal operation nothing reaches here. This exists for the
 * credentials that predate the requirement: a Sanctum token issued before MFA
 * was switched on, or a session still open from before. Without it, turning
 * `required_for` on would leave every already-signed-in account exempt until
 * their token expired.
 *
 * Built as a sibling of EnsureUserIsActive and answers the same two shapes of
 * caller: a bearer token gets JSON and loses the token, a browser session gets
 * logged out and redirected.
 */
class EnsureMfaSatisfied
{
    public function __construct(private readonly MfaPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->policy->requires($user)) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        // Only a real issued token carries a name. Sanctum hands back a
        // TransientToken when the caller is authenticated by session rather
        // than by bearer token, and that case belongs to the session branch
        // below.
        if ($token instanceof PersonalAccessToken) {
            // A token minted after a completed challenge carries that in its
            // name; anything else predates the requirement.
            if ($token->name === 'mfa-verified') {
                return $next($request);
            }

            // An API caller who still has to enroll keeps its token for the
            // enrollment endpoints only; everything else is refused.
            if ($this->policy->mustEnroll($user) && $request->routeIs('api.mfa.*')) {
                return $next($request);
            }

            $token->delete();

            return response()->json([
                'ok' => false,
                'data' => ['mfa_required' => true],
                'message' => 'Multi-factor authentication is required. Please sign in again.',
            ], 403);
        }

        // Stateless request that is nonetheless authenticated — an API route
        // reached without a session. There is nowhere for proof of a challenge
        // to live, so it cannot be produced; refuse rather than reach for a
        // session store that was never started.
        if (! $request->hasSession()) {
            return response()->json([
                'ok' => false,
                'data' => ['mfa_required' => true],
                'message' => 'Multi-factor authentication is required. Please sign in again.',
            ], 403);
        }

        // Owes a factor but has none: pinned to the setup screen rather than
        // turned away, since there is nothing they could present.
        if ($this->policy->mustEnroll($user)) {
            if ($request->routeIs('mfa.setup', 'mfa.setup.*', 'logout')) {
                return $next($request);
            }

            $this->rememberDestination($request);

            return redirect()->route('mfa.setup');
        }

        // Session flow: the login path marks the session once the challenge is
        // passed. An unmarked session was opened before MFA applied.
        if ($request->session()->get('mfa.verified_user_id') === $user->id) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'email' => 'Bitte melden Sie sich erneut an — Ihr Konto benötigt eine Zwei-Faktor-Bestätigung.',
        ]);
    }

    /**
     * Remember the page the user was on the way to before being pinned to
     * MFA setup, so that finishing setup brings them back to it
     * (MfaEnrollmentController::proceed() reads it through
     * redirect()->intended()).
     *
     * This is what keeps the registration funnels intact: a new Privatkunde is
     * sent from registration to /onboarding, a new Firmenkunde to
     * /onboarding/b2b. Without this, the detour through MFA setup dropped
     * that destination and everyone landed on the dashboard instead.
     *
     * Only GET page visits are remembered (a POST cannot be replayed by a
     * redirect), and the first destination wins: once the user is pinned to
     * setup, clicking around must not overwrite where registration was
     * actually taking them.
     */
    private function rememberDestination(Request $request): void
    {
        if (! $request->isMethod('GET') || $request->expectsJson()) {
            return;
        }

        if ($request->session()->has('url.intended')) {
            return;
        }

        $request->session()->put('url.intended', $request->fullUrl());
    }
}
