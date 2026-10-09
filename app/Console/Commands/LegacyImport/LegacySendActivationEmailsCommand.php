<?php

namespace App\Console\Commands\LegacyImport;

use App\Jobs\SendLegacyActivationMail;
use App\Models\LegacyActivationMail;
use App\Support\LegacyImport\LegacyActivation;
use Illuminate\Support\Facades\DB;

/**
 * Queues the one activation mail per user imported from Base44 — a one-time
 * link to set the password their account never had (see LegacyActivation).
 *
 * Nothing goes out by accident: without --dry-run it needs either --email
 * (one account, for testing) or --all (everyone due), refuses a non-HTTPS
 * APP_URL and a queue that discards jobs, and in production also
 * --confirm-production, like every legacy:* command.
 *
 * Rerunning is safe: a user already queued, sent or activated is skipped, so
 * a run interrupted halfway is simply run again. Failed mails are retried only
 * with --retry-failed.
 */
class LegacySendActivationEmailsCommand extends AbstractLegacyCommand
{
    protected $signature = 'legacy:send-activation-emails
        {--dry-run : Report who would be mailed; queue and change nothing}
        {--email= : Only this one imported user (for testing)}
        {--all : Queue the mail for every imported user it is due to}
        {--retry-failed : Also queue users whose mail failed before}
        {--limit=0 : Queue at most this many in this run (0 = no limit)}
        {--allow-insecure-url : Allow a non-HTTPS APP_URL (rehearsal servers only)}
        {--confirm-production : Required for a real run in production}';

    protected $description = 'Queue the activation email (one-time password setup link) for users imported from Base44';

    public function handle(LegacyActivation $activation): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $email = $this->option('email') !== null ? trim((string) $this->option('email')) : null;
        $all = (bool) $this->option('all');
        $retryFailed = (bool) $this->option('retry-failed');
        $limit = max(0, (int) $this->option('limit'));

        if ($email !== null && $all) {
            $this->error('Use either --email=… (one account) or --all (everyone), not both.');

            return self::FAILURE;
        }

        if (! $dryRun && $email === null && ! $all) {
            $this->error('Nothing sent. Choose --dry-run (report only), --email=… (one test account) or --all (every imported user).');

            return self::FAILURE;
        }

        if ($this->productionGuardFails($dryRun) || (! $dryRun && ! $this->readyToSend())) {
            return self::FAILURE;
        }

        $candidates = $activation->candidates($email);

        if ($email !== null && $candidates->isEmpty()) {
            $this->warn("{$email}: not mailed — ".$activation->exclusionReason($email).'.');

            return self::SUCCESS;
        }

        $tracking = LegacyActivationMail::whereIn('user_id', $candidates->pluck('user.id')->filter())->get()->keyBy('user_id');
        $due = 0;
        $queued = 0;
        $skipped = [];

        foreach ($candidates as ['map' => $map, 'user' => $user]) {
            $reason = $activation->assess($user, $user ? $tracking->get($user->id) : null, $retryFailed);

            if ($reason === null && $limit > 0 && $queued >= $limit) {
                $reason = 'over_limit_for_this_run';
            }

            if ($reason !== null) {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                if (! $dryRun && $user !== null && in_array($reason, ['inactive_user', 'invalid_email'], true)) {
                    $activation->recordSkip($user, $reason);
                }

                if ($email !== null) {
                    $this->warn("{$map->legacy_id}: not mailed — {$reason}.");
                }

                continue;
            }

            $due++;

            if ($dryRun) {
                continue;
            }

            if (! $activation->claim($user, $retryFailed)) {
                $skipped['claimed_by_another_run'] = ($skipped['claimed_by_another_run'] ?? 0) + 1;

                continue;
            }

            SendLegacyActivationMail::dispatch((int) LegacyActivationMail::where('user_id', $user->id)->value('id'));
            $queued++;

            if ($email !== null) {
                $this->info("{$user->email}: activation mail queued.");
            }
        }

        $this->report($candidates->count(), $due, $queued, $skipped, $dryRun);

        return self::SUCCESS;
    }

    /**
     * The link must point at the real portal over HTTPS, and the queue must
     * actually deliver: with QUEUE_CONNECTION=null the job is thrown away
     * while the row would read "queued".
     */
    private function readyToSend(): bool
    {
        $url = (string) config('app.url');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $insecure = ! str_starts_with($url, 'https://') || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false;

        if ($insecure && ! $this->option('allow-insecure-url')) {
            $this->error("APP_URL is {$url} — the activation link must use the final HTTPS domain. (Rehearsal only: --allow-insecure-url.)");

            return false;
        }

        $connection = (string) config('queue.default');

        if ($connection === 'null' || config("queue.connections.{$connection}.driver") === 'null') {
            $this->error('QUEUE_CONNECTION is null — queued mails would be thrown away. Use sync or a real queue with a worker.');

            return false;
        }

        if ($insecure) {
            $this->warn("Sending links to {$url} (--allow-insecure-url).");
        }

        return true;
    }

    /**
     * @param  array<string, int>  $skipped
     */
    private function report(int $candidates, int $due, int $queued, array $skipped, bool $dryRun): void
    {
        $this->newLine();
        $this->line($dryRun ? 'Dry run — nothing was queued or changed.' : 'Run complete.');

        $rows = [
            ['Imported Base44 users considered', $candidates],
            [$dryRun ? 'Would be mailed now' : 'Due this run', $due],
            ['Queued now', $queued],
        ];

        ksort($skipped);

        foreach ($skipped as $reason => $count) {
            $rows[] = ['Skipped: '.$reason, $count];
        }

        $this->table(['', 'Users'], $rows);

        $totals = DB::table('legacy_activation_mails')
            ->selectRaw('status, count(*) as n, sum(case when activated_at is not null then 1 else 0 end) as activated')
            ->groupBy('status')
            ->get();

        $this->table(['Tracked status (all runs)', 'Users', 'Of them activated'], $totals->map(fn ($row) => [$row->status, $row->n, $row->activated])->all());
    }
}
