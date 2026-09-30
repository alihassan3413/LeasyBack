<?php

namespace App\Services\Mfa;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * The way back in when the second factor is gone — a lost phone, a mailbox
 * that no longer receives.
 *
 * Codes are generated once, shown once, and stored only as hashes inside the
 * account's encrypted recovery column. Nobody can read them back out, this
 * class included; a code is spent by matching it and removing its hash.
 */
class MfaRecoveryCodeService
{
    /**
     * A fresh set of codes: the plaintext for the user to write down, and the
     * hashes to persist. The plaintext is returned and never stored.
     *
     * @return array{plain: array<int, string>, hashed: array<int, string>}
     */
    public function generate(): array
    {
        $plain = [];

        for ($i = 0; $i < (int) config('mfa.recovery.count'); $i++) {
            $plain[] = $this->code();
        }

        return [
            // Shown grouped, because that is what a person copies down.
            'plain' => $plain,
            // Hashed in the normalized form the user's typing is reduced to,
            // so the dash and the case they use when redeeming it cannot
            // change the comparison.
            'hashed' => array_map(fn (string $code) => Hash::make($this->normalize($code)), $plain),
        ];
    }

    /**
     * Spend one code.
     *
     * Every stored hash is checked even after a match, so a valid code and an
     * invalid one cost the same. The matched hash is dropped and the rest
     * written back, which is what makes a code single-use.
     */
    public function consume(User $user, #[SensitiveParameter] string $code): bool
    {
        $stored = $user->mfa_recovery_codes ?? [];

        if ($stored === []) {
            return false;
        }

        $candidate = $this->normalize($code);
        $remaining = [];
        $used = false;

        foreach ($stored as $hash) {
            if (! $used && Hash::check($candidate, $hash)) {
                $used = true;

                continue;
            }

            $remaining[] = $hash;
        }

        if (! $used) {
            return false;
        }

        $user->forceFill(['mfa_recovery_codes' => $remaining])->save();

        return true;
    }

    public function remaining(User $user): int
    {
        return count($user->mfa_recovery_codes ?? []);
    }

    /**
     * Typed back from paper, so the comparison ignores the grouping dash and
     * the case the user happened to use.
     */
    public function normalize(#[SensitiveParameter] string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    /**
     * `A1B2C-3D4E5`. Unambiguous characters only — no O/0 or I/1 confusion on
     * a code someone reads off a printout months later.
     */
    private function code(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $half = (int) config('mfa.recovery.half_length');
        $parts = [];

        for ($part = 0; $part < 2; $part++) {
            $chars = '';

            for ($i = 0; $i < $half; $i++) {
                $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $parts[] = $chars;
        }

        return implode('-', $parts);
    }
}
