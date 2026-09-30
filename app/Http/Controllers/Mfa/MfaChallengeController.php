<?php

namespace App\Http\Controllers\Mfa;

use App\Http\Controllers\Controller;
use App\Models\MfaLoginChallenge;
use App\Models\User;
use App\Services\Mfa\MfaChallengeService;
use App\Services\Mfa\MfaEmailCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The second step of signing in.
 *
 * Orchestration only: every decision about whether a ticket is live and
 * whether a code satisfies it belongs to MfaChallengeService, so the session
 * flow below and the token flow cannot reach different verdicts.
 *
 * The two flows differ in exactly two places — where the ticket is kept (the
 * guest session versus the request body) and what success produces (a
 * regenerated session versus a token named `mfa-verified`).
 */
class MfaChallengeController extends Controller
{
    public function __construct(
        private readonly MfaChallengeService $challenges,
        private readonly MfaEmailCodeService $emailCodes,
    ) {}

    /** The verification screen, for the browser flow. */
    public function show(Request $request): InertiaResponse|RedirectResponse
    {
        $challenge = $this->sessionChallenge($request);

        if ($challenge === null) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/MfaVerify', [
            'method' => $challenge->user?->mfa_method,
            'canUseRecoveryCode' => $challenge->user?->mfa_confirmed_at !== null,
            'emailCooldownSeconds' => $this->emailCodes->secondsUntilResend($challenge),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Finish the browser login.
     *
     * Only here, after the code is accepted, does a session come into being:
     * the password step deliberately left the user unauthenticated.
     */
    public function verify(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:64']]);

        $challenge = $this->sessionChallenge($request);

        if ($challenge === null) {
            return redirect()->route('login')->withErrors([
                'code' => 'Die Anmeldung ist abgelaufen. Bitte melden Sie sich erneut an.',
            ]);
        }

        $user = $challenge->user;

        if (! $this->challenges->attempt($challenge, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'Der Code ist ungültig oder abgelaufen.',
            ]);
        }

        return $this->completeSessionLogin($request, $user);
    }

    /** Mail a code for the browser flow. */
    public function sendEmail(Request $request): RedirectResponse
    {
        $challenge = $this->sessionChallenge($request);

        if ($challenge === null) {
            return redirect()->route('login');
        }

        return back()->with('status', $this->statusFor($this->emailCodes->send($challenge), $challenge));
    }

    // ------------------------------------------------------------------ api

    /**
     * Finish the token login.
     *
     * The ticket arrives in the body because an API caller has no session. It
     * is still only a lookup key — everything it refers to is re-read and
     * re-validated server side.
     */
    public function verifyApi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ticket' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'max:64'],
        ]);

        $challenge = $this->challenges->resolve($validated['ticket'], MfaLoginChallenge::PURPOSE_VERIFY);

        if ($challenge === null) {
            return $this->error('This sign-in has expired. Please sign in again.', 401);
        }

        $user = $challenge->user;

        if (! $this->challenges->attempt($challenge, $validated['code'])) {
            return $this->error('Invalid or expired code.', 422);
        }

        // The name is what EnsureMfaSatisfied reads to tell a challenged token
        // from one issued before the requirement existed.
        $token = $user->createToken('mfa-verified')->plainTextToken;

        return response()->json([
            'ok' => true,
            'data' => [
                'token' => $token,
                'user_id' => $user->id,
                'user_type' => $user->user_type,
            ],
            'message' => 'Login successful.',
        ]);
    }

    public function sendEmailApi(Request $request): JsonResponse
    {
        $validated = $request->validate(['ticket' => ['required', 'string', 'max:128']]);

        $challenge = $this->challenges->resolve($validated['ticket'], MfaLoginChallenge::PURPOSE_VERIFY);

        if ($challenge === null) {
            return $this->error('This sign-in has expired. Please sign in again.', 401);
        }

        $result = $this->emailCodes->send($challenge);

        return response()->json([
            'ok' => $result === MfaEmailCodeService::SENT,
            'data' => [
                'result' => $result,
                'retry_after_seconds' => $this->emailCodes->secondsUntilResend($challenge),
            ],
            'message' => $this->statusFor($result, $challenge),
        ], $result === MfaEmailCodeService::SENT ? 200 : 429);
    }

    // -------------------------------------------------------------- internals

    /**
     * The challenge this browser is holding.
     *
     * The session carries only the ticket; whether it still means anything is
     * decided against the database on every request.
     */
    private function sessionChallenge(Request $request): ?MfaLoginChallenge
    {
        $ticket = (string) $request->session()->get('mfa.ticket', '');

        if ($ticket === '') {
            return null;
        }

        $challenge = $this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY);

        if ($challenge === null) {
            $request->session()->forget('mfa.ticket');
        }

        return $challenge;
    }

    private function completeSessionLogin(Request $request, User $user): RedirectResponse
    {
        $request->session()->forget('mfa.ticket');

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        // Read by EnsureMfaSatisfied to tell this session from one that was
        // opened before the requirement applied.
        $request->session()->put('mfa.verified_user_id', $user->id);

        return redirect()->intended(route($user->homeRouteName(), absolute: false));
    }

    private function statusFor(string $result, MfaLoginChallenge $challenge): string
    {
        return match ($result) {
            MfaEmailCodeService::SENT => 'Wir haben Ihnen einen Code per E-Mail geschickt.',
            MfaEmailCodeService::COOLDOWN => 'Bitte warten Sie '.$this->emailCodes->secondsUntilResend($challenge).' Sekunden, bevor Sie einen neuen Code anfordern.',
            default => 'Es wurden zu viele Codes angefordert. Bitte melden Sie sich später erneut an.',
        };
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['ok' => false, 'data' => null, 'message' => $message], $status);
    }
}
