<?php

namespace App\Services\Mfa;

use App\Models\MfaLoginChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * The gap between a correct password and an authenticated caller.
 *
 * Every rule about that gap lives here — how long a ticket lasts, how many
 * wrong codes it survives, whether it may be used for the purpose it is being
 * presented for — so the session flow and the API flow cannot drift into
 * disagreeing about what a half-authenticated caller is allowed to do.
 *
 * The plaintext ticket exists only in the response that hands it out. What is
 * stored is its sha256, so a database read yields nothing that can be replayed.
 */
class MfaChallengeService
{
    public function __construct(
        private readonly MfaTotpService $totp,
        private readonly MfaRecoveryCodeService $recovery,
    ) {}

    /**
     * Open a challenge and return the plaintext ticket, which is the only time
     * it is ever readable.
     *
     * Any earlier challenge of the same purpose is dropped first: a user who
     * re-submits the login form should not leave usable tickets behind.
     */
    public function issue(User $user, string $purpose): string
    {
        $ticket = Str::random(64);

        DB::transaction(function () use ($user, $purpose, $ticket) {
            MfaLoginChallenge::where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->delete();

            MfaLoginChallenge::create([
                'user_id' => $user->id,
                'ticket_hash' => $this->hashTicket($ticket),
                'purpose' => $purpose,
                'expires_at' => now()->addMinutes((int) config('mfa.challenge.ttl_minutes')),
            ]);
        });

        return $ticket;
    }

    /**
     * The live challenge a ticket refers to, or null.
     *
     * Null covers every failure the caller must not be able to tell apart:
     * unknown ticket, expired, wrong purpose, or an account deactivated since
     * the password was accepted. An expired row is deleted on sight rather
     * than left to a sweeper.
     */
    public function resolve(#[SensitiveParameter] string $ticket, string $purpose): ?MfaLoginChallenge
    {
        $challenge = MfaLoginChallenge::with('user')
            ->where('ticket_hash', $this->hashTicket($ticket))
            ->where('purpose', $purpose)
            ->first();

        if ($challenge === null) {
            return null;
        }

        if ($challenge->expires_at->isPast()) {
            $challenge->delete();

            return null;
        }

        // Re-checked here, not just at password time: an account disabled
        // during the challenge must not be able to finish signing in.
        if ($challenge->user === null || ! $challenge->user->is_active) {
            $challenge->delete();

            return null;
        }

        return $challenge;
    }

    /**
     * Check a submitted code against every factor the account actually has.
     *
     * Returns true only if the challenge is spent as a result. A wrong code
     * costs an attempt, and running out destroys the challenge so the user
     * starts again from the password rather than grinding six digits.
     */
    public function attempt(MfaLoginChallenge $challenge, #[SensitiveParameter] string $code): bool
    {
        $user = $challenge->user;

        if ($user === null) {
            return false;
        }

        if ($challenge->attempts >= (int) config('mfa.challenge.max_attempts')) {
            $this->fail($challenge, 'attempts_exhausted');

            return false;
        }

        if ($this->matches($challenge, $user, $code)) {
            $challenge->delete();

            return true;
        }

        $challenge->increment('attempts');

        if ($challenge->attempts >= (int) config('mfa.challenge.max_attempts')) {
            $this->fail($challenge, 'attempts_exhausted');
        } else {
            $this->log('mfa.challenge.failed', $challenge, ['attempts' => $challenge->attempts]);
        }

        return false;
    }

    /**
     * Whether the code satisfies any factor, and the bookkeeping each one
     * needs on success.
     *
     * Order matters only for cost: the emailed code is a hash comparison, TOTP
     * is three HMACs, a recovery code is up to eight bcrypt checks.
     */
    private function matches(MfaLoginChallenge $challenge, User $user, #[SensitiveParameter] string $code): bool
    {
        if ($challenge->code_hash !== null && password_verify($this->digits($code), $challenge->code_hash)) {
            // The emailed code dies with the challenge; nulling it here means
            // a second use cannot succeed even if the row outlives this call.
            $challenge->forceFill(['code_hash' => null])->save();

            return true;
        }

        if ($user->mfa_secret !== null) {
            $step = $this->totp->verify($user->mfa_secret, $code, $user->mfa_last_used_step);

            if ($step !== null) {
                // Burning the step is what stops the same code being replayed
                // inside its own 30-second window.
                $user->forceFill(['mfa_last_used_step' => $step])->save();

                return true;
            }
        }

        // Only offered once enrollment is complete: a recovery code is a way
        // back into a configured account, not a way to skip configuring one.
        if ($user->mfa_confirmed_at !== null && $this->recovery->consume($user, $code)) {
            $this->log('mfa.recovery_code.used', $challenge, [
                'remaining' => $this->recovery->remaining($user),
            ]);

            return true;
        }

        return false;
    }

    public function hashTicket(#[SensitiveParameter] string $ticket): string
    {
        return hash('sha256', $ticket);
    }

    /**
     * Digits only. Authenticator apps and mail clients both like to render a
     * code with a space in the middle.
     */
    public function digits(#[SensitiveParameter] string $code): string
    {
        return preg_replace('/\D/', '', $code) ?? '';
    }

    private function fail(MfaLoginChallenge $challenge, string $reason): void
    {
        $this->log('mfa.challenge.destroyed', $challenge, ['reason' => $reason]);

        $challenge->delete();
    }

    /**
     * Enough to trace an incident, nothing that helps an attacker: ids and
     * counts only, never a code, a ticket or a secret.
     *
     * @param  array<string, mixed>  $context
     */
    private function log(string $event, MfaLoginChallenge $challenge, array $context = []): void
    {
        Log::info($event, [
            'challenge_id' => $challenge->id,
            'user_id' => $challenge->user_id,
            'purpose' => $challenge->purpose,
            ...$context,
        ]);
    }
}
