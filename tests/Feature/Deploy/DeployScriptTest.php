<?php

namespace Tests\Feature\Deploy;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Unit tests for the helper and safety-check functions of deploy/deploy.sh.
 * The script is only *sourced* (its entry point is guarded), so nothing is
 * deployed, installed, migrated or restarted. Everything runs on invented files
 * in a temp folder; system tools the checks consult are replaced by shims.
 */
class DeployScriptTest extends TestCase
{
    private string $tmp;

    /** Variables the PHPUnit process carries that the script would rightly treat as shadowing .env. */
    private const CLEAN_ENV = ['APP_ENV', 'APP_DEBUG', 'APP_URL', 'DB_CONNECTION', 'DB_DATABASE', 'MAIL_MAILER', 'QUEUE_CONNECTION',
        'BROADCAST_CONNECTION', 'BROADCAST_DRIVER', 'CACHE_STORE', 'SESSION_DRIVER', 'FILESYSTEM_DISK', 'DOCUMENTS_FILESYSTEM_DRIVER',
        'LEXWARE_INTEGRATION_MODE', 'LEGACY_IMPORT_SOURCE_PATH', 'PHP_VERSION', 'PRODUCTION_DOMAIN', 'DOMAIN', 'REHEARSAL_ALLOWED_APP_ENVS',
        'LEGACY_REHEARSAL_PRODUCTION_PATH'];

    private const GOOD_REHEARSAL_ENV = "APP_ENV=local\nAPP_URL=http://3.120.45.67\nMAIL_MAILER=log\nQUEUE_CONNECTION=null\nBROADCAST_CONNECTION=log\n"
        ."CACHE_STORE=database\nSESSION_DRIVER=database\nFILESYSTEM_DISK=local\nDOCUMENTS_FILESYSTEM_DRIVER=local\nLEXWARE_INTEGRATION_MODE=disabled\n"
        ."STRIPE_SECRET=\nAWS_ACCESS_KEY_ID=\nAWS_SECRET_ACCESS_KEY=\nREVERB_APP_KEY=\n";

    private const GOOD_PRODUCTION_ENV = "APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0\n"
        ."APP_URL=https://portal.leasyback.de\nMAIL_MAILER=sendgrid\nSENDGRID_API_KEY=SG.real\nMAIL_FROM_ADDRESS=\"service@leasyback.com\"\n"
        ."QUEUE_CONNECTION=database\nFILESYSTEM_DISK=s3\nDOCUMENTS_FILESYSTEM_DRIVER=s3\nAWS_ACCESS_KEY_ID=AKIAREAL\nAWS_SECRET_ACCESS_KEY=real\n"
        ."AWS_DEFAULT_REGION=eu-central-1\nAWS_BUCKET=leasyback-docs\nBROADCAST_CONNECTION=reverb\nREVERB_APP_ID=1\nREVERB_APP_KEY=k\nREVERB_APP_SECRET=s\n";

    protected function setUp(): void
    {
        parent::setUp();

        $probe = new Process(['bash', '-c', 'echo ok; command -v sqlite3 >/dev/null && command -v git >/dev/null && echo tools']);
        $probe->run();

        if (! str_contains($probe->getOutput(), "ok\ntools")) {
            $this->markTestSkipped('bash, sqlite3 and git are needed for the deploy script tests.');
        }

        $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deploy-test-'.Str::random(8);
        mkdir($this->tmp.DIRECTORY_SEPARATOR.'bin', 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmp) && is_dir($this->tmp)) {
            (new Process(['bash', '-c', 'rm -rf "$1"', '_', $this->tmp]))->run();
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $env
     * @return array{0: string, 1: int}
     */
    private function sh(string $snippet, array $env = []): array
    {
        $process = new Process(
            ['bash', '-c', 'source "$1"; set +eu; '.$snippet, '_', base_path('deploy/deploy.sh')],
            base_path(),
            $env + array_fill_keys(self::CLEAN_ENV, false) + ['PATH' => $this->tmp.'/bin:'.getenv('PATH')],
        );
        $process->run();

        return [trim($process->getOutput()), $process->getExitCode()];
    }

    /** "ok"/"refused", the last line after any reason the check printed. */
    private function verdict(string $check, array $env = []): string
    {
        $lines = explode("\n", $this->sh("if $check; then echo ok; else echo refused; fi", $env)[0]);

        return end($lines);
    }

    private function file(string $contents, string $name = ''): string
    {
        $path = $this->tmp.DIRECTORY_SEPARATOR.($name ?: 'env-'.Str::random(4));
        file_put_contents($path, $contents);

        return $path;
    }

    private function shim(string $name, string $script): void
    {
        $path = $this->tmp.'/bin/'.$name;
        file_put_contents($path, "#!/usr/bin/env bash\n".$script."\n");
        chmod($path, 0755);
    }

    /** Applies one KEY=value change to an env template (replace, or append when absent). */
    private function variant(string $base, string $key, string $value): string
    {
        $lines = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $key.'='.$value, $base, -1, $count);

        return $count ? $lines : $base.$key.'='.$value."\n";
    }

    // ------------------------------------------------------------- the script

    public function test_the_script_parses_and_has_a_guarded_entry_point(): void
    {
        $syntax = new Process(['bash', '-n', base_path('deploy/deploy.sh')]);
        $syntax->run();
        $code = file_get_contents(base_path('deploy/deploy.sh'));

        $this->assertSame(0, $syntax->getExitCode(), $syntax->getErrorOutput());
        $this->assertStringContainsString('set -Eeuo pipefail', $code);
        $this->assertStringContainsString('if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then', $code);
    }

    public function test_the_script_never_imports_wipes_or_recreates_data(): void
    {
        $executable = preg_replace('/^\s*#.*$/m', '', file_get_contents(base_path('deploy/deploy.sh')));

        foreach (['migrate:fresh', 'migrate:reset', 'migrate:refresh', 'db:wipe', 'git clean', 'install -m 0664 /dev/null'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $executable, $forbidden);
        }

        $this->assertDoesNotMatchRegularExpression('/artisan"?\s+legacy:/', $executable, 'the Base44 migration is never run by a deploy');

        $this->assertDoesNotMatchRegularExpression('/\brm\b[^\n]*\$SQLITE_FILE/', $executable, 'the database is never removed');
        $this->assertDoesNotMatchRegularExpression('/(rm|mv|cp|chmod|chown|>)\s[^\n]*(PROTECTED_EXPORT_DIR|base44-export)/', $executable, 'the Base44 export is never written');
        $this->assertSame(1, substr_count($executable, 'db:seed'), 'seeding happens in one place only');
        $this->assertMatchesRegularExpression('/if \[\[ \$SEED == true \]\]; then\s+step "Seeding/', $executable, 'and only behind --seed');
    }

    /** Workers and Reverb are only ever (re)started inside the production branch of deploy(). */
    public function test_workers_are_only_restarted_in_production(): void
    {
        $code = file_get_contents(base_path('deploy/deploy.sh'));
        $productionBlock = Str::between($code, 'if [[ $MODE == production ]]; then'."\n".'        step "Restarting queue workers', 'if [[ $MAINTENANCE_ON == true ]]; then');

        $this->assertStringContainsString('supervisorctl restart', $productionBlock);
        $this->assertSame(substr_count($code, 'supervisorctl restart'), substr_count($productionBlock, 'supervisorctl restart'));
        $this->assertStringNotContainsString('supervisorctl start', $code);
        $this->assertSame(1, substr_count($code, 'queue:restart'));
        $this->assertStringContainsString('queue:restart', $productionBlock);
    }

    // ------------------------------------------------------------- arguments

    public function test_arguments_require_exactly_one_mode_and_are_validated(): void
    {
        $rc = fn (string $args) => $this->sh("( parse_args $args ) >/dev/null 2>&1; echo rc=\$?")[0];

        $this->assertSame('rc=64', $rc(''), 'no mode');
        $this->assertSame('rc=64', $rc('--yes'), 'still no mode');
        $this->assertSame('rc=64', $rc('--rehearsal --production'), 'both modes');
        $this->assertSame('rc=64', $rc('--rehearsal --seed'), 'no seeding in rehearsal');
        $this->assertSame('rc=64', $rc('--rehearsal --allow-non-production-branch'));
        $this->assertSame('rc=64', $rc('--production --rollback --branch=main'), 'rollback takes no branch');
        $this->assertSame('rc=64', $rc('--rehearsal --branch'), 'branch without a name');
        $this->assertSame('rc=64', $rc('--rehearsal --branch='), 'empty branch');
        $this->assertSame('rc=64', $rc('--rehearsal --bogus'));
        $this->assertSame('rc=0', $rc('--rehearsal --yes'));
        $this->assertSame('rc=0', $rc('--production --yes --seed'));
        $this->assertSame('rc=0', $rc('--rehearsal --allow-dirty --no-build --no-migrate'));
    }

    public function test_both_branch_spellings_are_accepted(): void
    {
        $this->assertSame('rehearsal feat/base44-migration', $this->sh('parse_args --rehearsal --branch=feat/base44-migration --yes; echo "$MODE $BRANCH_ARG"')[0]);
        $this->assertSame('production main', $this->sh('parse_args --branch main --production; echo "$MODE $BRANCH_ARG"')[0]);
    }

    // --------------------------------------------------------------- helpers

    public function test_env_value_reads_plain_quoted_exported_and_commented_values(): void
    {
        $env = $this->file("A=plain\nB=\"quoted # value\"\nC='single'\nD=value # comment\nexport E=exported\nF=first\nF=second\nG=\n  H=indented\n");

        foreach (['A' => 'plain', 'B' => 'quoted # value', 'C' => 'single', 'D' => 'value', 'E' => 'exported', 'F' => 'second', 'G' => '', 'H' => 'indented', 'MISSING' => ''] as $key => $expected) {
            $this->assertSame($expected, $this->sh("env_value $env $key")[0], $key);
        }
    }

    public function test_hosts_are_normalised_and_ip_addresses_recognised(): void
    {
        $this->assertSame('portal.leasyback.de', $this->sh('url_host https://user@Portal.LeasyBack.de:443/dashboard')[0]);
        $this->assertSame('3.120.45.67', $this->sh('url_host http://3.120.45.67')[0]);
        $this->assertSame('ok', $this->verdict('is_ip_host 3.120.45.67'));
        $this->assertSame('ok', $this->verdict('is_ip_host ::1'));
        $this->assertSame('refused', $this->verdict('is_ip_host portal.leasyback.de'));
    }

    public function test_a_shell_variable_that_shadows_env_is_refused(): void
    {
        $env = $this->file("APP_ENV=local\n");

        $this->assertSame('ok', $this->verdict("chk_no_shadowing $env"));
        $this->assertSame('ok', $this->verdict("chk_no_shadowing $env", ['APP_ENV' => 'local']), 'same value is harmless');
        $this->assertSame('refused', $this->verdict("chk_no_shadowing $env", ['APP_ENV' => 'production']));
    }

    // -------------------------------------------------------------- rehearsal

    public function test_a_rehearsal_env_served_over_the_aws_ip_is_accepted(): void
    {
        $env = $this->file(self::GOOD_REHEARSAL_ENV);
        $checks = base_path('scripts/base44-rehearsal.sh');

        $this->assertSame('ok', $this->verdict("chk_rehearsal_app_env $env"));
        $this->assertSame('ok', $this->verdict("chk_rehearsal_url $env", ['PRODUCTION_DOMAIN' => 'portal.leasyback.de']));
        $this->assertSame('ok', $this->verdict("chk_rehearsal_integrations $checks $env"));
    }

    public function test_rehearsal_refuses_production_settings_and_live_integrations(): void
    {
        $checks = base_path('scripts/base44-rehearsal.sh');
        $cases = [
            ['chk_rehearsal_app_env', 'APP_ENV', 'production'],
            ['chk_rehearsal_app_env', 'APP_ENV', 'staging'],
            ['chk_rehearsal_app_env', 'APP_ENV', ''],
            ['chk_rehearsal_integrations', 'MAIL_MAILER', 'smtp'],
            ['chk_rehearsal_integrations', 'MAIL_MAILER', 'sendgrid'],
            ['chk_rehearsal_integrations', 'QUEUE_CONNECTION', 'database'],
            ['chk_rehearsal_integrations', 'QUEUE_CONNECTION', 'sync'],
            ['chk_rehearsal_integrations', 'BROADCAST_CONNECTION', 'reverb'],
            ['chk_rehearsal_integrations', 'DOCUMENTS_FILESYSTEM_DRIVER', 's3'],
            ['chk_rehearsal_integrations', 'FILESYSTEM_DISK', 's3'],
            ['chk_rehearsal_integrations', 'STRIPE_SECRET', 'sk_live_abc'],
            ['chk_rehearsal_integrations', 'AWS_ACCESS_KEY_ID', 'AKIAREAL'],
            ['chk_rehearsal_integrations', 'REVERB_APP_KEY', 'realkey'],
            ['chk_rehearsal_integrations', 'TUVSUD_API_KEY', 'real'],
            ['chk_rehearsal_integrations', 'PARTNER_WEBHOOK_SECRET', 'real'],
            ['chk_rehearsal_integrations', 'LEXWARE_INTEGRATION_MODE', 'live'],
        ];

        foreach ($cases as [$check, $key, $value]) {
            $env = $this->file($this->variant(self::GOOD_REHEARSAL_ENV, $key, $value));
            $args = $check === 'chk_rehearsal_integrations' ? "$checks $env" : $env;

            $this->assertSame('refused', $this->verdict("$check $args"), "$key=$value");
        }
    }

    public function test_another_rehearsal_app_env_must_be_approved_explicitly_and_production_never(): void
    {
        $staging = $this->file($this->variant(self::GOOD_REHEARSAL_ENV, 'APP_ENV', 'staging'));
        $production = $this->file($this->variant(self::GOOD_REHEARSAL_ENV, 'APP_ENV', 'production'));

        $this->assertSame('ok', $this->verdict("chk_rehearsal_app_env $staging", ['REHEARSAL_ALLOWED_APP_ENVS' => 'staging,rehearsal']));
        $this->assertSame('refused', $this->verdict("chk_rehearsal_app_env $production", ['REHEARSAL_ALLOWED_APP_ENVS' => 'production']));
    }

    public function test_rehearsal_refuses_the_production_domain(): void
    {
        $env = $this->file($this->variant(self::GOOD_REHEARSAL_ENV, 'APP_URL', 'https://Portal.leasyback.de'));

        $this->assertSame('refused', $this->verdict("chk_rehearsal_url $env", ['PRODUCTION_DOMAIN' => 'portal.leasyback.de']));
    }

    public function test_rehearsal_checks_are_refused_when_the_target_commit_has_none(): void
    {
        $env = $this->file(self::GOOD_REHEARSAL_ENV);

        $this->assertSame('refused', $this->verdict("chk_rehearsal_integrations {$this->tmp}/missing.sh $env"));
        $this->assertSame('refused', $this->verdict("chk_rehearsal_integrations {$this->file('', 'empty.sh')} $env"));
    }

    // ------------------------------------------------------------- production

    public function test_a_complete_production_env_is_accepted(): void
    {
        $this->assertSame('ok', $this->verdict("chk_production_env {$this->file(self::GOOD_PRODUCTION_ENV)}", ['PRODUCTION_DOMAIN' => 'portal.leasyback.de']));
    }

    public function test_production_refuses_missing_or_unsafe_configuration(): void
    {
        $cases = [
            ['APP_ENV', 'local'],
            ['APP_DEBUG', 'true'],
            ['APP_KEY', ''],
            ['APP_URL', 'http://portal.leasyback.de'],
            ['APP_URL', 'https://3.120.45.67'],
            ['APP_URL', 'https://localhost'],
            ['APP_URL', 'https://staging.leasyback.de'],
            ['MAIL_MAILER', 'log'],
            ['MAIL_MAILER', 'array'],
            ['SENDGRID_API_KEY', ''],
            ['MAIL_FROM_ADDRESS', ''],
            ['QUEUE_CONNECTION', 'null'],
            ['QUEUE_CONNECTION', 'sync'],
            ['DOCUMENTS_FILESYSTEM_DRIVER', ''],
            ['AWS_BUCKET', ''],
            ['REVERB_APP_SECRET', ''],
            ['DEKRA_PASSWORD', 'CHANGE_ME'],
        ];

        foreach ($cases as [$key, $value]) {
            $env = $this->file($this->variant(self::GOOD_PRODUCTION_ENV, $key, $value));

            $this->assertSame('refused', $this->verdict("chk_production_env $env", ['PRODUCTION_DOMAIN' => 'portal.leasyback.de']), "$key=$value");
        }
    }

    public function test_the_existing_domain_setting_is_the_production_domain_by_default(): void
    {
        $env = $this->file(self::GOOD_PRODUCTION_ENV);

        $this->assertSame('ok', $this->verdict("chk_production_env $env", ['DOMAIN' => 'portal.leasyback.de']));
        $this->assertSame('refused', $this->verdict("chk_production_env $env", ['DOMAIN' => 'leasyback.insuretechgurus.com']));
    }

    // ------------------------------------------------------------------ PHP-FPM

    private function fakeSystemctl(string ...$services): void
    {
        $lines = implode('\n', array_map(fn (string $s) => "$s loaded active running PHP FastCGI", $services));
        $this->shim('systemctl', 'printf "'.$lines.($services ? '\n' : '').'"');
    }

    public function test_the_running_php_fpm_version_is_detected_not_assumed(): void
    {
        $this->fakeSystemctl('php8.3-fpm.service');
        $this->assertSame(['8.3', 0], $this->sh('detect_php_fpm'));

        $this->fakeSystemctl('php8.4-fpm.service', 'php8.3-fpm.service');
        $this->assertSame(['8.4', 0], $this->sh('detect_php_fpm', ['PHP_VERSION' => '8.4']), 'a configured version that runs wins');
        $this->assertSame(1, $this->sh('detect_php_fpm 2>/dev/null')[1], 'two versions and no hint is refused');

        $this->fakeSystemctl('php8.3-fpm.service');
        $this->assertSame(1, $this->sh('detect_php_fpm 2>/dev/null', ['PHP_VERSION' => '8.4'])[1], 'a configured version that is not running is refused');

        $this->fakeSystemctl();
        $this->assertSame(1, $this->sh('detect_php_fpm 2>/dev/null')[1], 'no php-fpm at all');
    }

    // ------------------------------------------------------- repository state

    public function test_a_dirty_working_tree_is_refused_and_untracked_files_are_not(): void
    {
        $repo = $this->tmp.'/repo';
        (new Process(['bash', '-c', 'git init -q "$1" && cd "$1" && echo a > tracked && git add tracked && git -c user.email=t@t -c user.name=t commit -qm init', '_', $repo]))->mustRun();

        $this->assertSame('ok', $this->verdict("chk_clean_tree $repo"));

        file_put_contents($repo.'/untracked', 'x');
        $this->assertSame('ok', $this->verdict("chk_clean_tree $repo"), 'untracked files are not modifications');

        file_put_contents($repo.'/tracked', 'changed');
        $this->assertSame('refused', $this->verdict("chk_clean_tree $repo"));
    }

    public function test_a_scheduler_cron_for_the_app_is_found_and_comments_are_ignored(): void
    {
        $this->shim('crontab', 'printf "# * * * * * cd /var/www/LeasyBack && php artisan schedule:run\n* * * * * cd /var/www/Other && php artisan schedule:run\n"');
        $this->assertSame('', $this->sh('scheduler_cron_entries /var/www/LeasyBack')[0]);

        $this->shim('crontab', 'printf "* * * * * cd /var/www/LeasyBack && /usr/bin/php8.4 artisan schedule:run >> log 2>&1\n"');
        $this->assertStringContainsString('schedule:run', $this->sh('scheduler_cron_entries /var/www/LeasyBack')[0]);
    }

    // ------------------------------------------------------------------ SQLite

    public function test_the_backup_is_a_verified_private_copy_and_the_source_is_untouched(): void
    {
        $db = $this->tmp.'/database.sqlite';
        (new Process(['sqlite3', $db, 'create table t (v text); insert into t values ("a"), ("b");']))->mustRun();
        $before = hash_file('sha256', $db);

        [$backup, $rc] = $this->sh("backup_sqlite $db {$this->tmp}/backups");

        $this->assertSame(0, $rc, $backup);
        $this->assertFileExists($backup);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($backup)), -4));
        $this->assertSame('ok', $this->verdict("sqlite_ok $backup"));
        $this->assertSame("2\n", (new Process(['sqlite3', $backup, 'select count(*) from t;']))->mustRun()->getOutput());
        $this->assertSame($before, hash_file('sha256', $db), 'the live database is not modified');
    }

    public function test_a_damaged_database_fails_the_integrity_check(): void
    {
        $this->assertSame('refused', $this->verdict("sqlite_ok {$this->file(str_repeat('not a database', 100), 'broken.sqlite')}"));
    }

    public function test_the_log_directory_and_backups_stay_out_of_the_base44_export(): void
    {
        $this->assertSame('ok', $this->verdict('is_inside /secure/base44-export/run1 /secure/base44-export'));
        $this->assertSame('refused', $this->verdict('is_inside /secure/base44-export-copy /secure/base44-export'), 'a sibling with the same prefix is not inside');
    }
}
