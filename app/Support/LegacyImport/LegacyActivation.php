<?php

namespace App\Support\LegacyImport;

use App\Models\LegacyActivationMail;
use App\Models\LegacyImportMap;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Who gets the Base44 activation mail, and the link in it.
 *
 * Only users UserStep *imported* — a V2 account created from a Base44 user,
 * holding an unusable password. Everyone else is left alone: `linked` users
 * already had a V2 account and password; `skipped` ones (invalid email,
 * invited but never active, the manual-review cases, missing company) have no
 * account to activate.
 *
 * The link is a token of the `legacy_activation` password broker — Laravel's
 * password-reset mechanism with its own table and a longer life — so no
 * password is ever generated or mailed, and a normal "Passwort vergessen"
 * link is never affected.
 */
final class LegacyActivation
{
    public const BROKER = 'legacy_activation';

    /**
     * The imported Base44 users, as [map row, user] pairs; one when $email is given.
     *
     * @return Collection<int, array{map: LegacyImportMap, user: User|null}>
     */
    public function candidates(?string $email = null): Collection
    {
        $rows = LegacyImportMap::query()
            ->where('entity', 'user')
            ->where('status', 'imported')
            ->where('target_table', 'users')
            ->when($email !== null, fn ($query) => $query->where('legacy_id', mb_strtolower(trim($email))))
            ->orderBy('id')
            ->get();

        $users = User::whereIn('id', $rows->pluck('target_id')->map(fn ($id) => (int) $id))->get()->keyBy('id');

        return $rows->map(fn (LegacyImportMap $map) => ['map' => $map, 'user' => $users->get((int) $map->target_id)]);
    }

    /**
     * Why an address that is not a candidate gets nothing — for `--email`.
     */
    public function exclusionReason(string $email): string
    {
        $map = LegacyImportMap::where('entity', 'user')->where('legacy_id', mb_strtolower(trim($email)))->first();

        return match (true) {
            $map === null => 'not_imported_from_base44',
            $map->status === 'linked' => 'existing_v2_account_has_a_password',
            $map->status === 'skipped' => (string) ($map->payload['reason'] ?? 'skipped_by_import'),
            default => 'import_status_'.$map->status,
        };
    }

    /**
     * Null when the mail is due; otherwise why not.
     */
    public function assess(?User $user, ?LegacyActivationMail $tracked, bool $retryFailed): ?string
    {
        return match (true) {
            $user === null => 'user_deleted',
            $tracked?->activated_at !== null => 'already_activated',
            $tracked?->status === LegacyActivationMail::STATUS_SENT => 'already_sent',
            $tracked?->status === LegacyActivationMail::STATUS_QUEUED => 'already_queued',
            $tracked?->status === LegacyActivationMail::STATUS_FAILED && ! $retryFailed => 'failed_before_use_retry_failed',
            ! $user->is_active => 'inactive_user',
            filter_var($user->email, FILTER_VALIDATE_EMAIL) === false => 'invalid_email',
            default => null,
        };
    }

    /**
     * Marks the user's mail as queued, atomically — true only for the one
     * caller that did it, so two runs (or a rerun) never queue it twice. A
     * failed mail is taken again only with $retryFailed; a skipped one is
     * re-evaluated (an address fixed since is mailed).
     */
    public function claim(User $user, bool $retryFailed): bool
    {
        $now = now();

        $inserted = DB::table('legacy_activation_mails')->insertOrIgnore([
            'user_id' => $user->id,
            'email' => $user->email,
            'status' => LegacyActivationMail::STATUS_QUEUED,
            'queued_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 1) {
            return true;
        }

        $retryable = $retryFailed
            ? [LegacyActivationMail::STATUS_FAILED, LegacyActivationMail::STATUS_SKIPPED]
            : [LegacyActivationMail::STATUS_SKIPPED];

        return DB::table('legacy_activation_mails')
            ->where('user_id', $user->id)
            ->whereIn('status', $retryable)
            ->whereNull('activated_at')
            ->update([
                'email' => $user->email,
                'status' => LegacyActivationMail::STATUS_QUEUED,
                'skip_reason' => null,
                'queued_at' => $now,
                'updated_at' => $now,
            ]) === 1;
    }

    /** Remembers why an imported user was not mailed (only for reasons the user's data can change). */
    public function recordSkip(User $user, string $reason): void
    {
        LegacyActivationMail::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['email' => $user->email, 'status' => LegacyActivationMail::STATUS_SKIPPED, 'skip_reason' => $reason],
        );
    }

    /**
     * A fresh one-time link: the token is stored hashed by the broker and is
     * valid for `auth.passwords.legacy_activation.expire` minutes. Absolute,
     * on APP_URL.
     */
    public function link(User $user): string
    {
        $token = Password::broker(self::BROKER)->createToken($user);

        return route('legacy-activation.create', ['token' => $token, 'email' => $user->email]);
    }

    public function expiresInDays(): int
    {
        return max(1, intdiv((int) config('auth.passwords.'.self::BROKER.'.expire'), 60 * 24));
    }

    /** Whether this user is one the activation campaign concerns at all. */
    public function isImportedUser(User $user): bool
    {
        return LegacyImportMap::where('entity', 'user')
            ->where('status', 'imported')
            ->where('target_table', 'users')
            ->where('target_id', (string) $user->id)
            ->exists();
    }
}
