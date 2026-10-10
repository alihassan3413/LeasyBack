<?php

namespace App\Console\Commands\LegacyImport;

use App\Enums\B2bRolePreset;
use App\Support\LegacyImport\LegacyB2bUserCorrection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The client's corrections to the Base44 company users (see
 * LegacyB2bUserCorrection). Without --apply it only shows what it would do.
 * An applied run writes an audit file with the state before each change.
 */
class LegacyCorrectB2bUsersCommand extends AbstractLegacyCommand
{
    protected $signature = 'legacy:correct-b2b-users
        {--remove=* : E-mail of a Base44 user to remove (repeatable)}
        {--assign=* : email:kunde:role — the one company of the user (Base44 Kunde id) and role: company_administrator, standard_user or read_only (repeatable)}
        {--expect-admin=* : kunde:email — the only Company Administrator that company must end up with (repeatable)}
        {--activate-company=* : Base44 Kunde id of a company to switch back on (repeatable)}
        {--apply : Make the changes (default: preview only)}
        {--report-path= : Folder for the audit file}
        {--confirm-production : Required with --apply when APP_ENV=production}';

    protected $description = 'Apply the client review of the Base44 company users: remove, move or assign users (idempotent, audited)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $remove = array_values(array_unique(array_filter(array_map(fn ($e) => Str::lower(trim((string) $e)), (array) $this->option('remove')))));
        $assign = [];
        $expectAdmin = [];

        foreach ((array) $this->option('assign') as $value) {
            [$email, $kunde, $role] = array_pad(explode(':', trim((string) $value)), 3, '');
            $preset = B2bRolePreset::tryFrom($role);

            if ($email === '' || $kunde === '' || $preset === null) {
                $this->error("--assign={$value}: expected email:kunde:role, role one of company_administrator, standard_user, read_only.");

                return self::FAILURE;
            }

            $assign[] = ['email' => Str::lower($email), 'kunde' => $kunde, 'preset' => $preset];
        }

        foreach ((array) $this->option('expect-admin') as $value) {
            [$kunde, $email] = array_pad(explode(':', trim((string) $value), 2), 2, '');

            if ($kunde === '' || $email === '') {
                $this->error("--expect-admin={$value}: expected kunde:email.");

                return self::FAILURE;
            }

            $expectAdmin[$kunde] = Str::lower($email);
        }

        $activate = array_values(array_unique(array_filter(array_map('trim', (array) $this->option('activate-company')))));

        if ($remove === [] && $assign === [] && $activate === []) {
            $this->error('Nothing to do. Pass --remove=…, --assign=… and/or --activate-company=….');

            return self::FAILURE;
        }

        if (count(array_unique(array_column($assign, 'email'))) !== count($assign)) {
            $this->error('Each user may be assigned to one company only.');

            return self::FAILURE;
        }

        if ($this->productionGuardFails(! $apply)) {
            return self::FAILURE;
        }

        $batch = 'correction-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $correction = new LegacyB2bUserCorrection($remove, $assign, $expectAdmin, $activate, $batch);
        $steps = $correction->plan();

        $this->table(['Benutzer', 'Änderung', 'Status'], array_map(fn ($s) => [$s['subject'], $s['action'], $s['state']], $steps));

        if ($correction->isBlocked()) {
            $this->error('Blocked — fix the rows marked BLOCKIERT. Nothing was changed.');

            return self::FAILURE;
        }

        if (! collect($steps)->contains('state', LegacyB2bUserCorrection::PENDING)) {
            $this->info('Everything is already in place. Nothing to change.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('Preview only — nothing was changed. Run again with --apply to make these changes.');

            return self::SUCCESS;
        }

        $audit = $correction->apply();
        $path = rtrim($this->reportDirectory(), '/')."/{$batch}.json";

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }

        file_put_contents($path, json_encode(['batch' => $batch, 'applied_at' => now()->toIso8601String(), 'changes' => $audit], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Log::info('legacy: B2B user correction applied', ['batch' => $batch, 'changes' => count($audit), 'user_ids' => array_values(array_unique(array_column($audit, 'user_id')))]);

        $this->info(count($audit)." changes applied. Batch {$batch}. Audit: {$path}");

        return self::SUCCESS;
    }
}
