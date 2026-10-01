<?php

namespace App\Http\Controllers\Mfa;

use App\Http\Controllers\Controller;
use App\Models\MfaLoginChallenge;
use App\Models\User;
use App\Services\Mfa\MfaChallengeService;
use App\Services\Mfa\MfaEmailCodeService;
use App\Services\Mfa\MfaPolicy;
use App\Services\Mfa\MfaRecoveryCodeService;
use App\Services\Mfa\MfaTotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Turning a second factor on, and an admin turning someone else's off.
 *
 * Enrollment is a two-step handshake on purpose: `setup` hands out a candidate
 * secret and `confirm` only keeps it once the user has produced a code from it.
 * A secret that is stored but never confirmed is an abandoned attempt, and the
 * policy treats it as no factor at all — which is what stops a user locking
 * themselves out by scanning a QR and closing the tab.
 */
class MfaEnrollmentController extends Controller
{
    public function __construct(
        private readonly MfaTotpService $totp,
        private readonly MfaChallengeService $challenges,
        private readonly MfaEmailCodeService $emailCodes,
        private readonly MfaRecoveryCodeService $recovery,
    ) {}

    /**
     * The enrollment screen.
     *
     * The candidate secret is a page prop rather than something the page has
     * to go and fetch: this application speaks Inertia, not XHR, so there is
     * no client-side HTTP layer to call the JSON API with.
     *
     * An unconfirmed secret is reused rather than regenerated, or reloading
     * the page would silently invalidate a QR code the user had already
     * scanned.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();
        $enrolled = $user->mfa_confirmed_at !== null;
        $secret = null;
        $uri = null;

        if (! $enrolled) {
            $secret = $user->mfa_secret ?? $this->totp->generateSecret();

            if ($user->mfa_secret !== $secret) {
                $user->forceFill(['mfa_secret' => $secret, 'mfa_last_used_step' => null])->save();
            }

            $uri = $this->totp->provisioningUri($secret, (string) $user->email);
        }

        return Inertia::render('auth/MfaSetup', [
            'enrolled' => $enrolled,
            'method' => $user->mfa_method,
            'mandatory' => app(MfaPolicy::class)->mustEnroll($user),
            'email' => $user->email,
            'secret' => $secret,
            'otpauthUri' => $uri,
            'qrCode' => $uri === null ? null : $this->totp->qrSvg($uri),
            'recoveryCodes' => $request->session()->get('mfa.recovery_codes'),
            'status' => $request->session()->get('status'),
            // Where "Weiter" goes once setup is finished. Always this route,
            // never the dashboard directly: proceed() resolves the page the
            // user was on the way to (e.g. the onboarding funnel).
            'continueUrl' => route('mfa.setup.continue', absolute: false),
        ]);
    }

    /**
     * Leave the setup screen for wherever the user was going.
     *
     * EnsureMfaSatisfied stores the page that was interrupted (for a fresh
     * registration: /onboarding or /onboarding/b2b) as the intended URL before
     * pinning the user to setup. Sending them there — and only falling back to
     * their home page when nothing was interrupted — is what keeps the
     * registration funnel from being skipped by the MFA detour.
     *
     * Someone who still owes a factor is sent back to setup instead: the
     * middleware lets this route through while pinned, so it must not become
     * a way out of setup.
     */
    public function proceed(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (app(MfaPolicy::class)->mustEnroll($user)) {
            return redirect()->route('mfa.setup');
        }

        return redirect()->intended(route($user->homeRouteName(), absolute: false));
    }

    /** Browser enrollment: mail a code so email can be chosen as the factor. */
    public function sendEmailWeb(Request $request): RedirectResponse
    {
        $user = $request->user();

        $ticket = $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_ENROLL);
        $challenge = $this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_ENROLL);

        $result = $this->emailCodes->send($challenge);

        // The ticket stays server side; the page never needs to hold it.
        $request->session()->put('mfa.enroll_ticket', $ticket);

        return back()->with('status', $result === MfaEmailCodeService::SENT
            ? 'Wir haben Ihnen einen Code per E-Mail geschickt.'
            : 'Es wurden zu viele Codes angefordert. Bitte versuchen Sie es später erneut.');
    }

    /**
     * Browser enrollment, finished.
     *
     * The recovery codes are flashed rather than returned, because this is a
     * redirect: they survive exactly one render of the setup page and are then
     * unreachable, which is the behaviour the warning on that page promises.
     */
    public function confirmWeb(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'in:totp,email'],
            'code' => ['required', 'string', 'max:64'],
        ]);

        $user = $request->user();

        $payload = $validated + ['ticket' => $request->session()->get('mfa.enroll_ticket')];

        if (! $this->confirmed($user, $payload)) {
            throw ValidationException::withMessages([
                'code' => 'Der Code ist ungültig oder abgelaufen.',
            ]);
        }

        $codes = $this->enroll($user, $validated['method']);

        $request->session()->forget('mfa.enroll_ticket');
        $request->session()->put('mfa.verified_user_id', $user->id);

        return redirect()->route('mfa.setup')->with('mfa.recovery_codes', $codes);
    }

    /**
     * Begin authenticator enrollment.
     *
     * The secret is written unconfirmed and returned once. It is the only
     * response in this module that contains it, and it is readable exactly
     * until the user finishes or abandons setup.
     *
     * `otpauth_uri` is the string that belongs inside the QR image; rendering
     * it is the client's job, so no image is generated or stored here.
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        $secret = $this->totp->generateSecret();

        $user->forceFill([
            'mfa_secret' => $secret,
            // Deliberately cleared: restarting setup must invalidate whatever
            // the previous attempt produced.
            'mfa_confirmed_at' => null,
            'mfa_method' => null,
            'mfa_last_used_step' => null,
        ])->save();

        $uri = $this->totp->provisioningUri($secret, (string) $user->email);

        return response()->json([
            'ok' => true,
            'data' => [
                // The scannable form, rendered here so the secret never has to
                // reach a client-side QR library.
                'qr_code' => $this->totp->qrSvg($uri),
                'otpauth_uri' => $uri,
                // Only for the "can't scan?" path — typing the key by hand.
                'secret' => $secret,
            ],
            'message' => 'Scan the code with your authenticator app, then confirm.',
        ]);
    }

    /** Mail a code so the user can enroll with email as their factor. */
    public function sendEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        $ticket = $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_ENROLL);
        $challenge = $this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_ENROLL);

        $result = $this->emailCodes->send($challenge);

        return response()->json([
            'ok' => $result === MfaEmailCodeService::SENT,
            'data' => [
                'ticket' => $ticket,
                'result' => $result,
                'retry_after_seconds' => $this->emailCodes->secondsUntilResend($challenge),
            ],
            'message' => $result === MfaEmailCodeService::SENT
                ? 'We sent a code to your email address.'
                : 'Too many codes requested. Please try again later.',
        ], $result === MfaEmailCodeService::SENT ? 200 : 429);
    }

    /**
     * Finish enrollment with a code from whichever factor is being set up.
     *
     * Recovery codes are generated here and returned exactly once — this
     * response is the only time they are readable, which is why the UI has to
     * make the user acknowledge them.
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'in:totp,email'],
            'code' => ['required', 'string', 'max:64'],
            'ticket' => ['required_if:method,email', 'nullable', 'string', 'max:128'],
        ]);

        $user = $request->user();

        if (! $this->confirmed($user, $validated)) {
            return response()->json([
                'ok' => false,
                'data' => null,
                'message' => 'Invalid or expired code.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'method' => $validated['method'],
                'recovery_codes' => $this->enroll($user, $validated['method']),
            ],
            'message' => 'Multi-factor authentication is now active. Save your recovery codes.',
        ]);
    }

    /**
     * Switch the factor on and mint recovery codes.
     *
     * @return array<int, string> the plaintext codes, readable only here
     */
    private function enroll(User $user, string $method): array
    {
        $codes = $this->recovery->generate();

        DB::transaction(function () use ($user, $method, $codes) {
            $user->forceFill([
                'mfa_method' => $method,
                'mfa_confirmed_at' => now(),
                'mfa_recovery_codes' => $codes['hashed'],
                'mfa_email_confirmed_at' => $method === 'email' ? now() : $user->mfa_email_confirmed_at,
                // An email-only factor has no authenticator secret to keep.
                'mfa_secret' => $method === 'totp' ? $user->mfa_secret : null,
            ])->save();

            MfaLoginChallenge::where('user_id', $user->id)
                ->where('purpose', MfaLoginChallenge::PURPOSE_ENROLL)
                ->delete();
        });

        Log::info('mfa.enrolled', ['user_id' => $user->id, 'method' => $method]);

        return $codes['plain'];
    }

    /**
     * Admin removal, for the person who lost both their phone and their
     * recovery codes.
     *
     * Everything goes, not just the secret: leaving recovery codes behind
     * would let an old printout sign in against a factor that no longer
     * exists. Logged with both actors, because this is the one call that
     * removes someone's second factor without their consent.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $user->forceFill([
            'mfa_secret' => null,
            'mfa_recovery_codes' => null,
            'mfa_confirmed_at' => null,
            'mfa_method' => null,
            'mfa_last_used_step' => null,
            'mfa_email_confirmed_at' => null,
        ])->save();

        MfaLoginChallenge::where('user_id', $user->id)->delete();

        Log::warning('mfa.reset_by_admin', [
            'user_id' => $user->id,
            'admin_id' => $request->user()->id,
        ]);

        return response()->json([
            'ok' => true,
            'data' => ['user_id' => $user->id],
            'message' => 'Multi-factor authentication has been reset for this user.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function confirmed(User $user, array $validated): bool
    {
        if ($validated['method'] === 'totp') {
            if ($user->mfa_secret === null) {
                return false;
            }

            $step = $this->totp->verify($user->mfa_secret, $validated['code'], $user->mfa_last_used_step);

            if ($step === null) {
                return false;
            }

            $user->forceFill(['mfa_last_used_step' => $step])->save();

            return true;
        }

        $challenge = $this->challenges->resolve((string) $validated['ticket'], MfaLoginChallenge::PURPOSE_ENROLL);

        return $challenge !== null
            && $challenge->user_id === $user->id
            && $this->challenges->attempt($challenge, $validated['code']);
    }
}