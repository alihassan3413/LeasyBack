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

    /** A server without live integrations, served over a bare IP (the AWS copy). */
    private const IP_SERVER_ENV = "APP_ENV=local\nAPP_DEBUG=true\nAPP_KEY=base64:c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0\nAPP_URL=http://3.120.45.67\n"
        ."MAIL_MAILER=log\nQUEUE_CONNECTION=null\nBROADCAST_CONNECTION=log\nLEXWARE_INTEGRATION_MODE=disabled\n";

    private const PRODUCTION_ENV = "APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0\n"
        ."APP_URL=https://portal.leasyback.de\nMAIL_MAILER=sendgrid\nSENDGRID_API_KEY=SG.real\nQUEUE_CONNECTION=database\n"
        ."BROADCAST_CONNECTION=reverb\nREVERB_APP_ID=1\nREVERB_APP_KEY=k\nREVERB_APP_SECRET=s\n";

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

    /** Workers and Reverb are (re)started in one place only, restart_background(). */
    public function test_workers_are_restarted_in_one_place_only(): void
    {
        $code = (string) preg_replace('/^\s*#.*$/m', '', (string) file_get_contents(base_path('deploy/deploy.sh')));
        $block = Str::between($code, 'restart_background() {', "\n}\n");

        $this->assertSame(substr_count($code, 'supervisorctl restart'), substr_count($block, 'supervisorctl restart'));
        $this->assertStringNotContainsString('supervisorctl start', $code);
        $this->assertSame(1, substr_count($code, 'artisan queue:restart'));
        $this->assertStringContainsString('artisan queue:restart', $block);
    }

    /**
     * Shims for sudo, supervisorctl and artisan. $programs maps a supervisor
     * program to the state it reports after a restart; absent means "not
     * configured on this server". Every restart is appended to calls.log.
     *
     * @param  array<string, string>  $programs
     */
    private function fakeSupervisor(array $programs): void
    {
        $status = '';
        foreach ($programs as $name => $state) {
            $status .= sprintf('[[ "$*" == *"%1$s"* ]] && echo "%1$s:%1$s_00 %2$s pid 1";'."\n", $name, $state);
        }

        $this->shim('sudo', '[[ $1 == -n ]] && shift; exec "$@"');
        $this->shim('supervisorctl', 'echo "$*" >> '.$this->tmp.'/calls.log'."\n".'if [[ $1 == status ]]; then'."\n".$status.'exit 0; fi');
        $this->shim('fakephp', 'echo "$*" >> '.$this->tmp.'/calls.log');
        $this->shim('sleep', 'exit 0');
    }

    private function calls(): string
    {
        return is_file($this->tmp.'/calls.log') ? (string) file_get_contents($this->tmp.'/calls.log') : '';
    }

    public function test_workers_and_reverb_are_restarted_and_verified_when_configured(): void
    {
        $this->fakeSupervisor(['leasyback-worker' => 'RUNNING', 'leasyback-reverb' => 'RUNNING']);

        [$out, $rc] = $this->sh('PHP=fakephp; restart_background');

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('artisan queue:restart', $this->calls());
        $this->assertStringContainsString('restart leasyback-worker:*', $this->calls());
        $this->assertStringContainsString('restart leasyback-reverb', $this->calls());
    }

    public function test_a_server_without_workers_or_reverb_skips_them(): void
    {
        $this->fakeSupervisor([]);

        [$out, $rc] = $this->sh('PHP=fakephp; restart_background');

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('not configured on this server', $out);
        $this->assertStringNotContainsString('restart leasyback', $this->calls());
        $this->assertStringContainsString('artisan queue:restart', $this->calls(), 'the harmless signal is still sent');
    }

    public function test_a_worker_that_does_not_come_back_fails_the_deploy(): void
    {
        $this->fakeSupervisor(['leasyback-worker' => 'FATAL']);

        [$out, $rc] = $this->sh('PHP=fakephp; ( restart_background ); echo "rc=$?"');

        $this->assertStringContainsString('not running after restart', $out);
        $this->assertStringEndsWith('rc=1', $out);
    }

    // ------------------------------------------------------------- arguments

    public function test_arguments_are_validated_without_any_mode(): void
    {
        $rc = fn (string $args) => $this->sh("( parse_args $args ) >/dev/null 2>&1; echo rc=\$?")[0];

        $this->assertSame('rc=0', $rc(''), 'no arguments: deploy main');
        $this->assertSame('rc=0', $rc('--yes'));
        $this->assertSame('rc=0', $rc('--branch=feat/base44-migration --yes'));
        $this->assertSame('rc=0', $rc('--yes --seed'));
        $this->assertSame('rc=0', $rc('--allow-dirty --no-build --no-migrate'));
        $this->assertSame('rc=64', $rc('--rollback --branch=main'), 'rollback takes no branch');
        $this->assertSame('rc=64', $rc('--branch'), 'branch without a name');
        $this->assertSame('rc=64', $rc('--branch='), 'empty branch');
        $this->assertSame('rc=64', $rc('--bogus'));
    }

    /** The retired mode and branch flags still run (old command lines), ignored with a note. */
    public function test_retired_flags_are_accepted_and_ignored(): void
    {
        foreach (['--rehearsal', '--production', '--allow-non-production-branch'] as $flag) {
            [$out] = $this->sh("parse_args $flag --branch=feat/x --yes 2>&1; echo \"rc=\$? \$BRANCH_ARG\"");

            $this->assertStringContainsString('no longer needed', $out, $flag);
            $this->assertStringEndsWith('rc=0 feat/x', $out, $flag);
        }
    }

    /** No --branch means main — never a BRANCH left in config.sh. */
    public function test_the_branch_defaults_to_main_and_ignores_config(): void
    {
        $config = $this->tmp.'/cfg';
        mkdir($config);
        file_put_contents($config.'/config.sh', "BRANCH=\"feat/left-over\"\n");

        $this->assertSame('main', $this->sh("load_config $config; echo \"\$BRANCH\"")[0]);
        $this->assertSame('feat/base44-migration', $this->sh("parse_args --branch=feat/base44-migration; load_config $config; echo \"\$BRANCH\"")[0]);
    }

    /** The branch checks every deploy runs are still there; the mode machinery is gone. */
    public function test_every_branch_gets_the_same_checks_and_no_modes_remain(): void
    {
        $code = (string) file_get_contents(base_path('deploy/deploy.sh'));

        foreach (['$MODE', 'PRODUCTION_BRANCH', 'chk_rehearsal', 'chk_production_env', 'stop_background_for_rehearsal'] as $gone) {
            $this->assertStringNotContainsString($gone, $code, $gone);
        }
        $this->assertStringContainsString('git check-ref-format --branch "$BRANCH"', $code);
        $this->assertStringContainsString('does not exist on origin', $code);
        $this->assertStringContainsString('--allow-non-fast-forward if intended', $code);
        $this->assertStringContainsString('chk_clean_tree "$APP_DIR"', $code);
        $this->assertStringContainsString('database backup failed — not migrating', $code);
        $this->assertStringContainsString('fails integrity_check after migrating', $code);
        $this->assertStringContainsString('the application is not healthy', $code);
    }

    public function test_both_branch_spellings_are_accepted(): void
    {
        $this->assertSame('feat/base44-migration', $this->sh('parse_args --branch=feat/base44-migration --yes; echo "$BRANCH_ARG"')[0]);
        $this->assertSame('main', $this->sh('parse_args --branch main; echo "$BRANCH_ARG"')[0]);
    }

    // --------------------------------------------------------------- helpers

    public function test_env_value_reads_plain_quoted_exported_and_commented_values(): void
    {
        $env = $this->file("A=plain\nB=\"quoted # value\"\nC='single'\nD=value # comment\nexport E=exported\nF=first\nF=second\nG=\n  H=indented\n");

        foreach (['A' => 'plain', 'B' => 'quoted # value', 'C' => 'single', 'D' => 'value', 'E' => 'exported', 'F' => 'second', 'G' => '', 'H' => 'indented', 'MISSING' => ''] as $key => $expected) {
            $this->assertSame($expected, $this->sh("env_value $env $key")[0], $key);
        }
    }

    /** The health check reaches APP_URL's host through this server's nginx. */
    public function test_hosts_are_normalised(): void
    {
        $this->assertSame('portal.leasyback.de', $this->sh('url_host https://user@Portal.LeasyBack.de:443/dashboard')[0]);
        $this->assertSame('3.120.45.67', $this->sh('url_host http://3.120.45.67')[0]);
        $this->assertSame('::1', $this->sh('url_host http://[::1]:8080/up')[0]);
    }

    public function test_a_shell_variable_that_shadows_env_is_refused(): void
    {
        $env = $this->file("APP_ENV=local\n");

        $this->assertSame('ok', $this->verdict("chk_no_shadowing $env"));
        $this->assertSame('ok', $this->verdict("chk_no_shadowing $env", ['APP_ENV' => 'local']), 'same value is harmless');
        $this->assertSame('refused', $this->verdict("chk_no_shadowing $env", ['APP_ENV' => 'production']));
    }

    // -------------------------------------------------------------- .env basics

    /** Valid setups of either kind pass: a bare-IP copy without integrations, and production. */
    public function test_ordinary_server_envs_pass_the_basics(): void
    {
        $this->assertSame('ok', $this->verdict("chk_env_basics {$this->file(self::IP_SERVER_ENV)}"));
        $this->assertSame('ok', $this->verdict("chk_env_basics {$this->file(self::PRODUCTION_ENV)}"));
    }

    public function test_the_basics_refuse_what_no_server_should_run_with(): void
    {
        $cases = [
            [self::PRODUCTION_ENV, 'APP_KEY', ''],
            [self::IP_SERVER_ENV, 'APP_KEY', ''],
            [self::PRODUCTION_ENV, 'APP_DEBUG', 'true'],
            [self::PRODUCTION_ENV, 'APP_DEBUG', 'TRUE'],
            [self::PRODUCTION_ENV, 'DEKRA_PASSWORD', 'CHANGE_ME'],
            [self::IP_SERVER_ENV, 'MAIL_PASSWORD', '"CHANGE_ME"'],
        ];

        foreach ($cases as [$base, $key, $value]) {
            $this->assertSame('refused', $this->verdict("chk_env_basics {$this->file($this->variant($base, $key, $value))}"), "$key=$value");
        }
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
