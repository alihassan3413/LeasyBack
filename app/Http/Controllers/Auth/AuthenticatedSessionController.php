<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\MfaLoginChallenge;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Services\Mfa\MfaChallengeService;
use App\Services\Mfa\MfaPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Credentials are proved but this is not yet a login. An account that
        // owes a second factor is signed straight back out and handed a
        // ticket: regenerating the session first would mean the password
        // alone had produced an authenticated browser.
        if (($challenge = $this->mfaChallenge($request)) !== null) {
            return $challenge;
        }

        $request->session()->regenerate();

        // Read by EnsureMfaSatisfied, which cannot otherwise tell a session
        // that never needed a factor from one opened before the requirement.
        $request->session()->put('mfa.verified_user_id', $request->user()->id);

        $user = $request->user();

        $context = app(B2bContext::class);

        if (
            $user->user_type === UserType::Firmenkunde &&
            $context->activeMembership($user) === null &&
            $context->hasInactiveMembership($user)
        ) {
            Auth::logout();

            return redirect('/login')
                ->withErrors([
                    'email' => 'Your company access has been disabled.',
                ]);
        }

        $home = route($user->homeRouteName(), absolute: false);

        return redirect()->intended($home);
    }

    /**
     * Turn a password-only login into an MFA challenge, or null if this
     * account does not owe one.
     *
     * The guard is dropped again immediately: between here and a verified
     * code the caller must hold nothing but a ticket.
     */
    private function mfaChallenge(LoginRequest $request): ?RedirectResponse
    {
        $user = $request->user();

        if (! app(MfaPolicy::class)->requires($user)) {
            return null;
        }

        // Someone who owes a factor but has not set one up has nothing to
        // present. Challenging them would lock them out for good, so they are
        // let in and pinned to the setup page by EnsureMfaSatisfied until they
        // finish — the session exists but reaches nothing else.
        if (! app(MfaPolicy::class)->hasEnrolled($user)) {
            return null;
        }

        $ticket = app(MfaChallengeService::class)->issue($user, MfaLoginChallenge::PURPOSE_VERIFY);

        Auth::guard('web')->logout();

        $request->session()->put('mfa.ticket', $ticket);

        return redirect()->route('mfa.verify');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('success', 'Sie wurden abgemeldet.');
    }
}
