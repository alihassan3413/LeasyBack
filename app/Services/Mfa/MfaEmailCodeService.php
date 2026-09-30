<?php

namespace App\Services\Mfa;

use App\Mail\MfaCode;
use App\Models\MfaLoginChallenge;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The emailed six-digit code.
 *
 * The plaintext code exists for the length of one method call: it is generated,
 * handed to the mailable, and forgotten. What survives is its hash on the
 * challenge. It is never returned to a caller, never logged, and cannot be read
 * back out of the database.
 *
 * Both limits are answered from the challenge row rather than a cache, so
 * flushing the cache cannot reset someone's send budget.
 */
class MfaEmailCodeService
{
    public const SENT = 'sent';

    public const COOLDOWN = 'cooldown';

    public const TOO_MANY = 'too_many';

    /**
     * Send a code for this challenge, or say why not.
     *
     * Returns one of the constants above; the caller turns that into a status
     * and a wait time. Refusals are deliberately cheap and do not touch the
     * mailer.
     */
    public function send(MfaLoginChallenge $challenge): string
    {
        $user = $challenge->user;

        if ($user === null) {
            return self::TOO_MANY;
        }

        if ($this->secondsUntilResend($challenge) > 0) {
            return self::COOLDOWN;
        }

        if ($this->sendsInWindow($challenge) >= (int) config('mfa.email.max_sends')) {
            Log::warning('mfa.email.send_limit_reached', [
                'challenge_id' => $challenge->id,
                'user_id' => $challenge->user_id,
            ]);

            return self::TOO_MANY;
        }

        $code = $this->code();

        $challenge->forceFill([
            'code_hash' => Hash::make($code),
            'sends' => $challenge->sends + 1,
            'last_sent_at' => now(),
            // An emailed code must not outlive its own validity even if the
            // surrounding challenge would have lasted longer.
            'expires_at' => now()->addMinutes((int) config('mfa.email.ttl_minutes')),
        ])->save();

        Mail::to($user->email)->send(new MfaCode(
            code: $code,
            minutes: (int) config('mfa.email.ttl_minutes'),
            name: (string) $user->name,
        ));

        // The code itself is never part of this record.
        Log::info('mfa.email.sent', [
            'challenge_id' => $challenge->id,
            'user_id' => $challenge->user_id,
            'sends' => $challenge->sends,
        ]);

        return self::SENT;
    }

    /** Seconds the caller must wait before "resend" does anything. */
    public function secondsUntilResend(MfaLoginChallenge $challenge): int
    {
        if ($challenge->last_sent_at === null) {
            return 0;
        }

        $ready = $challenge->last_sent_at->addSeconds((int) config('mfa.email.resend_cooldown_seconds'));

        return max(0, (int) ceil(now()->diffInSeconds($ready, false)));
    }

    /**
     * Sends counted against the rolling window.
     *
     * A challenge is reissued on every fresh login, so the count lives with
     * the challenge and the window is bounded by the challenge's own age.
     */
    public function sendsInWindow(MfaLoginChallenge $challenge): int
    {
        $window = (int) config('mfa.email.max_sends_window_minutes');

        if ($challenge->created_at !== null && $challenge->created_at->lt(now()->subMinutes($window))) {
            return 0;
        }

        return $challenge->sends;
    }

    /**
     * Six digits from a cryptographically secure source, leading zeros
     * included — `random_int` rather than `rand`, and padded rather than
     * ranged from 100000, so every code in the space is equally likely.
     */
    private function code(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
