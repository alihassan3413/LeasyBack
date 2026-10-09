<?php

namespace App\Jobs;

use App\Mail\LegacyAccountActivation;
use App\Models\LegacyActivationMail;
use App\Support\LegacyImport\LegacyActivation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one Base44 activation mail, queued. The token is created here, at
 * send time, so a retry carries a fresh link and an old one never lingers.
 *
 * Idempotent: a mail already sent, or a user who activated meanwhile, is left
 * alone. After the last attempt the row is marked failed with the reason;
 * `--retry-failed` takes it again.
 */
class SendLegacyActivationMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $trackingId) {}

    public function handle(LegacyActivation $activation): void
    {
        $tracked = LegacyActivationMail::find($this->trackingId);

        if ($tracked === null || $tracked->status === LegacyActivationMail::STATUS_SENT) {
            return;
        }

        $user = $tracked->user;

        if ($user === null || $tracked->activated_at !== null) {
            $tracked->update(['status' => LegacyActivationMail::STATUS_SKIPPED, 'skip_reason' => $user === null ? 'user_deleted' : 'already_activated']);

            return;
        }

        $tracked->increment('attempts');

        Mail::to($user->email)->send(new LegacyAccountActivation($user, $activation->link($user), $activation->expiresInDays()));

        $tracked->update([
            'status' => LegacyActivationMail::STATUS_SENT,
            'email' => $user->email,
            'sent_at' => now(),
            'last_error' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        LegacyActivationMail::whereKey($this->trackingId)
            ->where('status', '!=', LegacyActivationMail::STATUS_SENT)
            ->update([
                'status' => LegacyActivationMail::STATUS_FAILED,
                'failed_at' => now(),
                'last_error' => mb_substr((string) $exception?->getMessage(), 0, 1000),
            ]);
    }
}
