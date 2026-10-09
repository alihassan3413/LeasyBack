<?php

namespace App\Listeners;

use App\Models\LegacyActivationMail;
use App\Models\User;
use App\Support\LegacyImport\LegacyActivation;
use Illuminate\Auth\Events\PasswordReset;

/**
 * A Base44-imported user set a password — through the activation link or a
 * normal "Passwort vergessen" reset. Either way the account is activated, and
 * the activation mail must not be sent (again). Users not imported from
 * Base44 are not touched.
 */
class MarkLegacyActivationComplete
{
    public function __construct(private readonly LegacyActivation $activation) {}

    public function handle(PasswordReset $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $tracked = LegacyActivationMail::where('user_id', $user->id)->first();

        if ($tracked !== null) {
            $tracked->activated_at ??= now();
            $tracked->save();

            return;
        }

        if ($this->activation->isImportedUser($user)) {
            LegacyActivationMail::create([
                'user_id' => $user->id,
                'email' => $user->email,
                'status' => LegacyActivationMail::STATUS_SKIPPED,
                'skip_reason' => 'activated_before_mailing',
                'activated_at' => now(),
            ]);
        }
    }
}
