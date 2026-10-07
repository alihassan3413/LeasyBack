<?php

namespace Tests\Feature\LegacyImport;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Unit tests for the helper and safety-check functions of
 * scripts/base44-rehearsal.sh. The script is only *sourced* (its entry point is
 * guarded), so nothing is installed, migrated or imported, and no database is
 * opened. Everything runs on invented files in a temp folder.
 */
class RehearsalScriptHelpersTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $probe = new Process(['bash', '-c', 'echo ok; command -v readlink >/dev/null && echo has-readlink']);
        $probe->run();

        if (! str_contains($probe->getOutput(), "ok\nhas-readlink")) {
            $this->markTestSkipped('A POSIX bash is needed for the rehearsal script helper tests.');
        }

        $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rehearsal-test-'.Str::random(8);
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmp) && is_dir($this->tmp)) {
            (new Process(['bash', '-c', 'rm -rf "$1"', '_', $this->tmp]))->run();
        }

        parent::tearDown();
    }

    /** Converts to the form bash sees (git-bash on Windows wants /c/...). */
    private function posix(string $path): string
    {
        $process = new Process(['bash', '-c', 'cygpath -u "$1" 2>/dev/null || printf %s "$1"', '_', $path]);
        $process->run();

        return trim($process->getOutput());
    }

    /**
     * @param  array<string, string>  $env
     * @return array{0: string, 1: int}
     */
    private function sh(string $snippet, array $env = []): array
    {
        $script = $this->posix(base_path('scripts/base44-rehearsal.sh'));
        // the PHPUnit process has its own APP_ENV, DB_DATABASE... which the script rightly treats as shadowing
        $clean = array_fill_keys(['APP_ENV', 'APP_URL', 'DB_CONNECTION', 'DB_DATABASE', 'MAIL_MAILER', 'QUEUE_CONNECTION', 'BROADCAST_CONNECTION', 'BROADCAST_DRIVER', 'CACHE_STORE', 'SESSION_DRIVER', 'FILESYSTEM_DISK', 'DOCUMENTS_FILESYSTEM_DRIVER', 'LEXWARE_INTEGRATION_MODE', 'LEGACY_IMPORT_SOURCE_PATH', 'REHEARSAL_PROD_DIR', 'REHEARSAL_PROD_DB', 'REHEARSAL_PROD_URL', 'LEGACY_REHEARSAL_PRODUCTION_PATH'], false);
        $process = new Process(['bash', '-c', 'source "$1"; set +e; '.$snippet, '_', $script], base_path(), $env + $clean);
        $process->run();

        return [trim($process->getOutput()), $process->getExitCode()];
    }

    /** The last line: "ok"/"refused" after any reason the check printed. */
    private function verdict(string $snippet, array $env = []): string
    {
        $lines = explode("\n", $this->sh($snippet, $env)[0]);

        return end($lines);
    }

    private function envFile(string $contents): string
    {
        $path = $this->tmp.DIRECTORY_SEPARATOR.'env-'.Str::random(4);
        file_put_contents($path, $contents);

        return $this->posix($path);
    }

    public function test_the_script_parses_as_valid_bash_and_has_a_guarded_entry_point(): void
    {
        $script = $this->posix(base_path('scripts/base44-rehearsal.sh'));
        $syntax = new Process(['bash', '-n', $script]);
        $syntax->run();

        $this->assertSame(0, $syntax->getExitCode(), $syntax->getErrorOutput());
        $this->assertStringContainsString('if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then', file_get_contents(base_path('scripts/base44-rehearsal.sh')));
        $this->assertStringContainsString('set -Eeuo pipefail', file_get_contents(base_path('scripts/base44-rehearsal.sh')));
    }

    public function test_the_script_never_seeds_and_never_writes_to_the_production_tree(): void
    {
        $code = file_get_contents(base_path('scripts/base44-rehearsal.sh'));
        $executable = preg_replace('/^\s*#.*$/m', '', $code);

        $this->assertStringNotContainsString('db:seed', $executable);
        $this->assertStringNotContainsString('migrate:fresh', $executable);
        $this->assertStringNotContainsString('migrate:reset', $executable);
        $this->assertStringNotContainsString('db:wipe', $executable);
        $this->assertDoesNotMatchRegularExpression('/\$(PROTECTED_)?PROD_DIR\/[^"\s]*["\s]*>>?\s/', $executable, 'no redirect into the production tree');
        $this->assertDoesNotMatchRegularExpression('/(rm|mv|cp|chmod|chown|touch|sqlite3 "\$PROD_DB")\s[^\n|;]*\$(PROTECTED_)?PROD_(DIR|DB)/', $executable);
    }

    public function test_env_get_reads_plain_quoted_and_commented_values_and_the_last_assignment_wins(): void
    {
        $env = $this->envFile("A=plain\nB=\"quoted value\"\nC='single'\nD=value # trailing comment\nexport E=exported\nF=first\nF=second\nG=\n");

        $this->assertSame('plain', $this->sh("env_get $env A")[0]);
        $this->assertSame('quoted value', $this->sh("env_get $env B")[0]);
        $this->assertSame('single', $this->sh("env_get $env C")[0]);
        $this->assertSame('value', $this->sh("env_get $env D")[0]);
        $this->assertSame('exported', $this->sh("env_get $env E")[0]);
        $this->assertSame('second', $this->sh("env_get $env F")[0]);
        $this->assertSame('', $this->sh("env_get $env G")[0]);
        $this->assertSame('', $this->sh("env_get $env MISSING")[0]);
    }

    public function test_a_shell_variable_shadows_the_env_file(): void
    {
        $env = $this->envFile("APP_ENV=local\n");

        $this->assertSame('local', $this->sh("ENV_FILE=$env; effective APP_ENV")[0]);
        $this->assertSame('production', $this->sh("ENV_FILE=$env; effective APP_ENV", ['APP_ENV' => 'production'])[0]);
        $this->assertStringContainsString('shell variable APP_ENV', $this->sh("ENV_FILE=$env; chk_no_shadowing", ['APP_ENV' => 'production'])[0]);
        $this->assertSame('ok', $this->verdict("ENV_FILE=$env; if chk_no_shadowing; then echo ok; else echo bad; fi", ['APP_ENV' => 'local']));
    }

    public function test_url_host_normalises_schemes_ports_paths_and_case(): void
    {
        $this->assertSame('app.example.test', $this->sh("url_host 'HTTPS://App.Example.test:8443/path?x=1'")[0]);
        $this->assertSame('rehearsal.local', $this->sh("url_host 'http://user:pw@rehearsal.local/'")[0]);
        $this->assertSame('', $this->sh("url_host ''")[0]);
    }

    public function test_laravel_reports_an_unquoted_null_queue_as_empty_and_the_script_accepts_exactly_that(): void
    {
        // The real chain: .env -> Dotenv/Env -> config value -> JSON -> the script's php_cfg.
        $dir = $this->tmp.DIRECTORY_SEPARATOR.'dotenv';
        mkdir($dir, 0700, true);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'.env', "QUEUE_CONNECTION=null\nBROADCAST_CONNECTION=null\n");

        $laravel = new Process([PHP_BINARY, '-r', '
            require $argv[1];
            Dotenv\Dotenv::create(Illuminate\Support\Env::getRepository(), $argv[2])->load();
            echo json_encode([
                "queue" => env("QUEUE_CONNECTION", "database"),
                "broadcast" => env("BROADCAST_CONNECTION", "null"),
            ]);
        ', '--', base_path('vendor/autoload.php'), $dir], null, ['QUEUE_CONNECTION' => false, 'BROADCAST_CONNECTION' => false]);
        $laravel->mustRun();

        $json = $laravel->getOutput();
        $this->assertSame('{"queue":null,"broadcast":null}', $json, 'Laravel turns the literal null into a real null, not the string "null"');

        $json = str_replace("'", '', $json);
        $this->assertSame('', $this->sh("CFG_JSON='$json'; php_cfg queue")[0], 'which the script reads as an empty value');

        $env = $this->envFile("QUEUE_CONNECTION=null\nBROADCAST_CONNECTION=null\n");
        $this->assertSame('ok', $this->verdict("CFG_JSON='$json'; ENV_FILE=$env; if chk_effective_queue; then echo ok; else echo refused; fi"));
        $this->assertSame('ok', $this->verdict("CFG_JSON='$json'; ENV_FILE=$env; if chk_effective_broadcast; then echo ok; else echo refused; fi"));
    }

    public function test_an_empty_effective_queue_or_broadcast_is_safe_only_when_null_was_configured(): void
    {
        $queue = fn (string $json, string $envContents) => $this->verdict("CFG_JSON='$json'; ENV_FILE={$this->envFile($envContents)}; if chk_effective_queue; then echo ok; else echo refused; fi");
        $broadcast = fn (string $json, string $envContents) => $this->verdict("CFG_JSON='$json'; ENV_FILE={$this->envFile($envContents)}; if chk_effective_broadcast; then echo ok; else echo refused; fi");

        // accepted: the null driver, however Laravel renders it
        $this->assertSame('ok', $queue('{"queue":null}', "QUEUE_CONNECTION=null\n"));
        $this->assertSame('ok', $queue('{"queue":"null"}', "QUEUE_CONNECTION=null\n"));
        $this->assertSame('ok', $queue('{"queue":null}', "QUEUE_CONNECTION='null'\n"));

        // refused: empty without an explicit null, or any real queue
        $this->assertSame('refused', $queue('{"queue":null}', "APP_NAME=x\n"), 'empty and nothing configured');
        $this->assertSame('refused', $queue('{"queue":null}', "QUEUE_CONNECTION=\n"), 'empty and configured empty');
        $this->assertSame('refused', $queue('{"queue":null}', "QUEUE_CONNECTION=sync\n"), 'configured sync but Laravel says empty');
        $this->assertSame('refused', $queue('{"queue":"sync"}', "QUEUE_CONNECTION=sync\n"), 'sync is never allowed');
        $this->assertSame('refused', $queue('{"queue":"database"}', "QUEUE_CONNECTION=null\n"), 'a real queue is refused even when .env says null');
        $this->assertSame('refused', $queue('{"queue":"redis"}', "QUEUE_CONNECTION=redis\n"));

        $this->assertSame('ok', $broadcast('{"broadcast":null}', "BROADCAST_CONNECTION=null\n"));
        $this->assertSame('ok', $broadcast('{"broadcast":null}', "BROADCAST_DRIVER=null\n"));
        $this->assertSame('ok', $broadcast('{"broadcast":"log"}', "BROADCAST_CONNECTION=log\n"));
        $this->assertSame('refused', $broadcast('{"broadcast":null}', "APP_NAME=x\n"));
        $this->assertSame('refused', $broadcast('{"broadcast":"reverb"}', "BROADCAST_CONNECTION=reverb\n"));
        $this->assertSame('refused', $broadcast('{"broadcast":"pusher"}', "BROADCAST_CONNECTION=null\n"));
    }

    public function test_masking_hides_emails_and_phone_numbers_but_keeps_dates_and_ids(): void
    {
        [$out] = $this->sh("printf '%s' 'mail anna.beispiel@firma.example call +49 30 123456 or 030 12345678 on 2026-10-06 batch 8f14e45f-ceea-467f-a0e6-3c5bb2d4d9d1' | mask_pii");

        $this->assertStringNotContainsString('anna.beispiel', $out);
        $this->assertStringContainsString('a***@f***.example', $out);
        $this->assertStringNotContainsString('123456', $out);
        $this->assertStringNotContainsString('12345678', $out);
        $this->assertStringContainsString('2026-10-06', $out);
        $this->assertStringContainsString('8f14e45f-ceea-467f-a0e6-3c5bb2d4d9d1', $out);
    }

    public function test_is_inside_means_below_or_equal_and_not_merely_a_shared_prefix(): void
    {
        $this->assertSame('yes', $this->sh('if is_inside /srv/prod/storage /srv/prod; then echo yes; else echo no; fi')[0]);
        $this->assertSame('yes', $this->sh('if is_inside /srv/prod /srv/prod; then echo yes; else echo no; fi')[0]);
        $this->assertSame('yes', $this->sh('if is_inside /srv/prod/a/../b /srv/prod/; then echo yes; else echo no; fi')[0]);
        $this->assertSame('no', $this->sh('if is_inside /srv/prod-migration-staging /srv/prod; then echo yes; else echo no; fi')[0]);
        $this->assertSame('no', $this->sh('if is_inside /srv/other /srv/prod; then echo yes; else echo no; fi')[0]);
        $this->assertSame('no', $this->sh('if is_inside /srv/prod ""; then echo yes; else echo no; fi')[0]);
    }

    public function test_integration_credentials_are_found_unless_blank_or_test_values(): void
    {
        $env = $this->envFile(implode("\n", [
            'STRIPE_SECRET=sk_live_abc123',
            'STRIPE_WEBHOOK_SECRET=whsec_realvalue',
            'TUVSUD_TOKEN=abcdef',
            'TUVSUD_USER_NAME=someone',
            'AWS_ACCESS_KEY_ID=AKIAEXAMPLE',
            'AWS_BUCKET=prod-bucket',
            'REVERB_APP_SECRET=hunter2',
            'PARTNER_WEBHOOK_SIGNING_SECRET=xyz',
            'VAPID_PRIVATE_KEY=privatekey',
            // all fine below
            'STRIPE_KEY=',
            'STRIPE_PUBLISHABLE_KEY=pk_test_123',
            'TUVSUD_PRODUCT_KEY=test-key',
            'AWS_SECRET_ACCESS_KEY=fake-secret',
            'VAPID_PUBLIC_KEY=publicvalue',
            'APP_NAME=LeasyBack',
            'MAIL_PASSWORD=whatever',
            'MAIL_MAILER=log',
        ])."\n");

        [$out] = $this->sh("integration_secret_findings $env | sort");
        $found = explode("\n", $out);

        foreach (['AWS_ACCESS_KEY_ID', 'AWS_BUCKET', 'PARTNER_WEBHOOK_SIGNING_SECRET', 'REVERB_APP_SECRET', 'STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET', 'TUVSUD_TOKEN', 'TUVSUD_USER_NAME', 'VAPID_PRIVATE_KEY'] as $key) {
            $this->assertTrue(count(array_filter($found, fn ($l) => str_starts_with($l, $key))) === 1, "{$key} must be flagged: {$out}");
        }

        foreach (['STRIPE_KEY', 'STRIPE_PUBLISHABLE_KEY', 'TUVSUD_PRODUCT_KEY', 'AWS_SECRET_ACCESS_KEY', 'VAPID_PUBLIC_KEY', 'APP_NAME', 'MAIL_PASSWORD', 'MAIL_MAILER'] as $key) {
            $this->assertEmpty(array_filter($found, fn ($l) => str_starts_with($l, $key.' ') || $l === $key), "{$key} must not be flagged");
        }

        $this->assertStringContainsString('STRIPE_SECRET (live key)', $out);
    }

    public function test_a_live_stripe_key_is_caught_under_any_name(): void
    {
        $env = $this->envFile("SOMETHING_ODD=sk_live_zzz\n");

        $this->assertSame('SOMETHING_ODD (live key)', $this->sh("integration_secret_findings $env")[0]);
    }

    public function test_the_environment_checks_accept_the_rehearsal_values_and_refuse_everything_else(): void
    {
        $good = $this->envFile("APP_ENV=local\nMAIL_MAILER=log\nQUEUE_CONNECTION=null\nBROADCAST_CONNECTION=log\nCACHE_STORE=database\nSESSION_DRIVER=database\nLEXWARE_INTEGRATION_MODE=disabled\nAPP_URL=http://rehearsal.local\n");
        $run = fn (string $env, string $check, array $vars = []) => $this->verdict("ENV_FILE=$env; if $check; then echo ok; else echo refused; fi", $vars + ['REHEARSAL_PROD_URL' => 'https://leasyback.example']);

        foreach (['chk_app_env_local', 'chk_mail_log', 'chk_queue_and_broadcast', 'chk_integrations', 'chk_app_url', 'chk_storage_not_s3'] as $check) {
            $this->assertSame('ok', $run($good, $check), $check);
        }

        $cases = [
            ['chk_app_env_local', "APP_ENV=production\n"],
            ['chk_app_env_local', "APP_ENV=staging\n"],
            ['chk_mail_log', "MAIL_MAILER=smtp\n"],
            ['chk_mail_log', "MAIL_MAILER=array\n"],
            ['chk_queue_and_broadcast', "QUEUE_CONNECTION=database\nBROADCAST_CONNECTION=log\n"],
            ['chk_queue_and_broadcast', "QUEUE_CONNECTION=sync\nBROADCAST_CONNECTION=log\n"],
            ['chk_queue_and_broadcast', "QUEUE_CONNECTION=redis\nBROADCAST_CONNECTION=log\n"],
            ['chk_queue_and_broadcast', "QUEUE_CONNECTION=null\nBROADCAST_CONNECTION=reverb\n"],
            ['chk_queue_and_broadcast', "QUEUE_CONNECTION=null\nBROADCAST_CONNECTION=log\nCACHE_STORE=redis\n"],
            ['chk_queue_and_broadcast', "QUEUE_CONNECTION=null\nBROADCAST_CONNECTION=log\nSESSION_DRIVER=redis\n"],
            ['chk_integrations', "STRIPE_SECRET=sk_live_x\nLEXWARE_INTEGRATION_MODE=disabled\n"],
            ['chk_integrations', "LEXWARE_INTEGRATION_MODE=live\n"],
            ['chk_integrations', "LEXWARE_INTEGRATION_MODE=\n"],
            ['chk_app_url', "APP_URL=https://leasyback.example/dashboard\n"],
            ['chk_app_url', "APP_URL=\n"],
            ['chk_storage_not_s3', "DOCUMENTS_FILESYSTEM_DRIVER=s3\n"],
            ['chk_storage_not_s3', "FILESYSTEM_DISK=s3\n"],
        ];

        foreach ($cases as [$check, $contents]) {
            $this->assertSame('refused', $run($this->envFile($contents), $check), $check.' with '.trim($contents));
        }
    }

    public function test_the_production_directory_is_refused_even_through_a_different_path_spelling(): void
    {
        $prod = $this->tmp.DIRECTORY_SEPARATOR.'prod';
        mkdir($prod.DIRECTORY_SEPARATOR.'storage', 0700, true);
        mkdir($this->tmp.DIRECTORY_SEPARATOR.'prod-migration-staging', 0700, true);
        $p = $this->posix($prod);
        $sibling = $this->posix($this->tmp.DIRECTORY_SEPARATOR.'prod-migration-staging');

        $check = fn (string $repo) => $this->verdict("REPO=$repo; if chk_not_production_dir; then echo ok; else echo refused; fi", ['REHEARSAL_PROD_DIR' => $p]);

        $this->assertSame('refused', $check($p));
        $this->assertSame('refused', $check("$p/storage"));
        $this->assertSame('refused', $check("$p/storage/../storage"));
        $this->assertSame('ok', $check($sibling), 'a sibling that merely shares the prefix is fine');
    }

    public function test_the_literal_production_path_is_refused_whatever_the_override_says(): void
    {
        foreach (['/var/www/LeasyBack', '/var/www/LeasyBack/storage'] as $repo) {
            $this->assertSame('refused', $this->verdict("REPO=$repo; if chk_not_production_dir; then echo ok; else echo refused; fi", ['REHEARSAL_PROD_DIR' => '/somewhere/else']));
        }
    }

    // ------------------------------------------- protected production path

    public function test_the_protected_production_path_defaults_to_var_www_leasyback(): void
    {
        $this->assertSame('/var/www/LeasyBack (from built-in default)', $this->sh('describe_protected_prod_dir')[0]);
        $this->assertSame('ok', $this->verdict('REPO=/srv/rehearsal-repo; ENV_FILE=; if chk_protected_prod_dir; then echo ok; else echo refused; fi'));
    }

    /**
     * The AWS host: /var/www/LeasyBack is the rehearsal copy today and becomes
     * production after sign-off, so the old live server is named instead.
     */
    public function test_an_explicit_override_lets_var_www_leasyback_be_the_rehearsal_copy(): void
    {
        $override = ['LEGACY_REHEARSAL_PRODUCTION_PATH' => '/nonexistent-old-production'];

        $this->assertSame('/nonexistent-old-production (from LEGACY_REHEARSAL_PRODUCTION_PATH)', $this->sh('describe_protected_prod_dir', $override)[0]);
        $this->assertSame('ok', $this->verdict('REPO=/var/www/LeasyBack; ENV_FILE=; if chk_protected_prod_dir && chk_not_production_dir; then echo ok; else echo refused; fi', $override));
        $this->assertSame(
            'refused',
            $this->verdict('REPO=/var/www/LeasyBack; if chk_not_production_dir; then echo ok; else echo refused; fi'),
            'without the override /var/www/LeasyBack stays production',
        );
    }

    public function test_the_overridden_path_is_protected_exactly_like_the_default(): void
    {
        $t = $this->posix($this->tmp);
        mkdir($this->tmp.DIRECTORY_SEPARATOR.'old-prod'.DIRECTORY_SEPARATOR.'storage', 0700, true);
        mkdir($this->tmp.DIRECTORY_SEPARATOR.'old-prod'.DIRECTORY_SEPARATOR.'logs', 0700, true);
        $override = ['LEGACY_REHEARSAL_PRODUCTION_PATH' => "$t/old-prod"];

        foreach (["$t/old-prod", "$t/old-prod/storage", "$t/old-prod/storage/../storage"] as $repo) {
            $this->assertSame('refused', $this->verdict("REPO=$repo; if chk_not_production_dir; then echo ok; else echo refused; fi", $override), $repo);
        }

        $this->assertSame('refused', $this->verdict("REPO=/srv/repo; LOG_ROOT=$t/old-prod/logs; if chk_log_root; then echo ok; else echo refused; fi", $override), 'log root inside it');
    }

    public function test_the_database_identity_checks_hold_against_the_overridden_path(): void
    {
        $t = $this->posix($this->tmp);
        mkdir($this->tmp.DIRECTORY_SEPARATOR.'old-prod'.DIRECTORY_SEPARATOR.'database', 0700, true);
        file_put_contents($this->tmp.'/old-prod/database/database.sqlite', 'prod');
        file_put_contents($this->tmp.'/copy.sqlite', 'copy');
        $prodDb = "$t/old-prod/database/database.sqlite";
        $vars = ['LEGACY_REHEARSAL_PRODUCTION_PATH' => "$t/old-prod", 'REHEARSAL_PROD_DB' => $prodDb];
        $check = fn (string $db) => $this->verdict("ENV_FILE={$this->envFile("DB_CONNECTION=sqlite\nDB_DATABASE=$db\n")}; if chk_database; then echo ok; else echo refused; fi", $vars);

        $this->assertSame('ok', $check("$t/copy.sqlite"));
        $this->assertSame('refused', $check($prodDb), 'the production database itself');

        $made = new Process(['bash', '-c', 'ln "$1" "$2" 2>/dev/null; [ "$(stat -c %h "$1" 2>/dev/null || stat -f %l "$1")" = 2 ] && echo made', '_', $prodDb, "$t/hardlinked.sqlite"]);
        $made->run();

        if (trim($made->getOutput()) === 'made') {
            $this->assertSame('refused', $check("$t/hardlinked.sqlite"), 'same inode as production');
        }
    }

    public function test_symlinks_into_the_overridden_path_are_refused(): void
    {
        $t = $this->posix($this->tmp);
        $setup = new Process(['bash', '-c', 'mkdir -p "$1/old-prod/storage" "$1/repo/storage/app"; ln -s "$1/old-prod/storage" "$1/repo/storage/app/evil" 2>/dev/null; [ -L "$1/repo/storage/app/evil" ] && echo made', '_', $t]);
        $setup->run();

        if (trim($setup->getOutput()) !== 'made') {
            $this->markTestIncomplete('Symlinks are not supported on this filesystem.');
        }

        $this->assertSame('refused', $this->verdict("REPO=$t/repo; if chk_symlinks; then echo ok; else echo refused; fi", ['LEGACY_REHEARSAL_PRODUCTION_PATH' => "$t/old-prod"]));
    }

    /** The override moves one path; every environment rule stays exactly as strict. */
    public function test_the_environment_checks_are_unchanged_by_the_override(): void
    {
        $vars = ['LEGACY_REHEARSAL_PRODUCTION_PATH' => '/nonexistent-old-production', 'REHEARSAL_PROD_URL' => 'https://leasyback.example'];
        $run = fn (string $contents, string $check) => $this->verdict("ENV_FILE={$this->envFile($contents)}; if $check; then echo ok; else echo refused; fi", $vars);

        $this->assertSame('refused', $run("APP_ENV=production\n", 'chk_app_env_local'));
        $this->assertSame('refused', $run("MAIL_MAILER=smtp\n", 'chk_mail_log'));
        $this->assertSame('refused', $run("QUEUE_CONNECTION=database\nBROADCAST_CONNECTION=log\n", 'chk_queue_and_broadcast'));
        $this->assertSame('refused', $run("STRIPE_SECRET=sk_live_x\nLEXWARE_INTEGRATION_MODE=disabled\n", 'chk_integrations'));
        $this->assertSame('refused', $run("LEXWARE_INTEGRATION_MODE=live\n", 'chk_integrations'));
        $this->assertSame('refused', $run("APP_URL=https://leasyback.example\n", 'chk_app_url'));
    }

    public function test_an_empty_or_malformed_override_is_refused(): void
    {
        $check = fn (string $value) => $this->verdict('REPO=/srv/rehearsal-repo; ENV_FILE=; if chk_protected_prod_dir; then echo ok; else echo refused; fi', ['LEGACY_REHEARSAL_PRODUCTION_PATH' => $value]);

        $this->assertSame('refused', $check(''), 'set but empty');
        $this->assertSame('refused', $check('   '), 'whitespace only');
        $this->assertSame('refused', $check('old-production'), 'relative');
        $this->assertSame('refused', $check('/'), 'the filesystem root');
        $this->assertSame('refused', $check('/srv/old production'), 'contains whitespace');
        $this->assertSame('refused', $check('/srv/rehearsal-repo'), 'the working copy itself');
        $this->assertSame('refused', $check('/srv/rehearsal-repo/storage'), 'inside the working copy');
        $this->assertSame('ok', $check('/srv/old-production'));
    }

    /** Explicit means typed into the shell for this run, not inherited from the rehearsal .env. */
    public function test_the_override_is_only_honoured_from_the_shell(): void
    {
        $env = $this->envFile("APP_ENV=local\nLEGACY_REHEARSAL_PRODUCTION_PATH=/nonexistent-old-production\n");

        $this->assertSame('refused', $this->verdict("REPO=/srv/rehearsal-repo; ENV_FILE=$env; if chk_protected_prod_dir; then echo ok; else echo refused; fi", ['LEGACY_REHEARSAL_PRODUCTION_PATH' => '/nonexistent-old-production']));
        $this->assertSame(
            '/var/www/LeasyBack (from built-in default)',
            $this->sh("ENV_FILE=$env; describe_protected_prod_dir")[0],
            'a value in .env alone changes nothing',
        );
    }

    /** The real entry point: the path is logged first, and a bad override stops the run before anything is written. */
    public function test_the_runner_logs_the_effective_path_and_stops_on_an_empty_override(): void
    {
        $clean = array_fill_keys(['APP_ENV', 'APP_URL', 'DB_CONNECTION', 'DB_DATABASE', 'MAIL_MAILER', 'QUEUE_CONNECTION', 'REHEARSAL_PROD_DIR', 'REHEARSAL_PROD_DB', 'REHEARSAL_PROD_URL'], false);
        $logRoot = $this->tmp.DIRECTORY_SEPARATOR.'logs';
        $process = new Process(
            ['bash', $this->posix(base_path('scripts/base44-rehearsal.sh')), 'check', '--log-root', $this->posix($logRoot)],
            base_path(),
            ['LEGACY_REHEARSAL_PRODUCTION_PATH' => ''] + $clean,
        );
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(2, $process->getExitCode(), $output);
        $this->assertStringContainsString('protected production path:  (from LEGACY_REHEARSAL_PRODUCTION_PATH)', $output);
        $this->assertStringContainsString('the protected production path is not usable', $output);
        $this->assertDirectoryDoesNotExist($logRoot, 'nothing is written before the refusal');
    }

    public function test_the_rehearsal_database_must_be_its_own_regular_file(): void
    {
        $prodDb = $this->tmp.DIRECTORY_SEPARATOR.'prod.sqlite';
        $copy = $this->tmp.DIRECTORY_SEPARATOR.'copy.sqlite';
        file_put_contents($prodDb, 'prod');
        file_put_contents($copy, 'copy');
        $p = $this->posix($prodDb);
        $c = $this->posix($copy);
        $env = fn (string $db) => $this->envFile("DB_CONNECTION=sqlite\nDB_DATABASE=$db\n");

        $check = fn (string $db) => $this->verdict("ENV_FILE={$env($db)}; if chk_database; then echo ok; else echo refused; fi", ['REHEARSAL_PROD_DIR' => '/nonexistent-prod', 'REHEARSAL_PROD_DB' => $p]);

        $this->assertSame('ok', $check($c));
        $this->assertSame('refused', $check($p), 'the production database itself');
        $this->assertSame('refused', $check('relative/path.sqlite'), 'relative path');
        $this->assertSame('refused', $check($this->posix($this->tmp).'/missing.sqlite'), 'missing file');
    }

    public function test_a_symlink_or_hard_link_to_the_production_database_is_refused(): void
    {
        $prodDb = $this->tmp.DIRECTORY_SEPARATOR.'prod.sqlite';
        file_put_contents($prodDb, 'prod');
        $p = $this->posix($prodDb);
        $t = $this->posix($this->tmp);

        // (git-bash on Windows silently copies on `ln -s`; only a real link counts)
        $kinds = [
            'symlink' => ['ln -s "$1" "$2" 2>/dev/null; [ -L "$2" ] && echo made'],
            'hard link' => ['ln "$1" "$2" 2>/dev/null; [ "$(stat -c %h "$1")" = 2 ] && echo made'],
        ];

        foreach ($kinds as $label => [$command]) {
            $target = "$t/linked-".str_replace(' ', '-', $label).'.sqlite';
            $made = new Process(['bash', '-c', $command, '_', $p, $target]);
            $made->run();

            if (trim($made->getOutput()) !== 'made') {
                $this->markTestIncomplete("{$label}s are not supported on this filesystem");
            }

            $this->assertSame('refused', $this->verdict("ENV_FILE={$this->envFile("DB_CONNECTION=sqlite\nDB_DATABASE=$target\n")}; if chk_database; then echo ok; else echo refused; fi", ['REHEARSAL_PROD_DIR' => '/nonexistent-prod', 'REHEARSAL_PROD_DB' => $p]), "a {$label} to production must be refused");
        }
    }

    public function test_symlinks_into_production_are_found_and_harmless_ones_are_not(): void
    {
        $t = $this->posix($this->tmp);
        $setup = new Process(['bash', '-c', 'mkdir -p "$1/prod/storage" "$1/repo/storage/app" "$1/other"; ln -s "$1/prod/storage" "$1/repo/storage/app/evil" 2>/dev/null; ln -s "$1/other" "$1/repo/storage/app/fine" 2>/dev/null; [ -L "$1/repo/storage/app/evil" ] && echo made', '_', $t]);
        $setup->run();

        if (trim($setup->getOutput()) !== 'made') {
            $this->markTestIncomplete('Symlinks are not supported on this filesystem.');
        }

        $out = $this->sh("symlinks_into $t/repo $t/prod")[0];

        $this->assertStringContainsString('evil', $out);
        $this->assertStringNotContainsString('fine', $out);
    }

    public function test_the_export_must_be_complete_and_outside_both_repositories(): void
    {
        $dir = $this->tmp.DIRECTORY_SEPARATOR.'export';
        mkdir($dir, 0700, true);

        foreach (['Kunde_export.csv', 'LeasyBack_Flottenmanagement-users.csv', 'Fahrzeug_export.csv', 'Auftrag_export.csv', 'Auftragskommentar_export.csv', 'AuftragStatushistorie_export.csv', 'Dateianhang_export.csv', 'FahrzeugLead_export.csv', 'PendingEinladung_export.csv', 'Benachrichtigung_export.csv'] as $file) {
            file_put_contents($dir.DIRECTORY_SEPARATOR.$file, "id\n1\n");
        }

        $d = $this->posix($dir);
        $run = fn (string $repo, string $source) => $this->verdict("REPO=$repo; ENV_FILE=/dev/null; if chk_export; then echo ok; else echo refused; fi", ['LEGACY_IMPORT_SOURCE_PATH' => $source, 'REHEARSAL_PROD_DIR' => '/nonexistent-prod']);

        $this->assertSame('ok', $run('/srv/rehearsal-repo', $d));
        $this->assertSame('refused', $run('/srv/rehearsal-repo', 'relative/dir'), 'relative path');
        $this->assertSame('refused', $run($d, $d), 'export inside the rehearsal repo');
        $this->assertSame('refused', $run('/srv/rehearsal-repo', ''), 'unset');

        unlink($dir.DIRECTORY_SEPARATOR.'Fahrzeug_export.csv');
        $this->assertSame('refused', $run('/srv/rehearsal-repo', $d), 'a file is missing');
    }

    public function test_the_log_root_must_be_outside_both_repositories(): void
    {
        $check = fn (string $root) => $this->verdict("REPO=/srv/rehearsal-repo; LOG_ROOT=$root; if chk_log_root; then echo ok; else echo refused; fi", ['REHEARSAL_PROD_DIR' => '/srv/prod']);

        $this->assertSame('ok', $check('/secure/legacy-rehearsal'));
        $this->assertSame('refused', $check('/srv/rehearsal-repo/storage/logs'));
        $this->assertSame('refused', $check('/srv/prod/logs'));
        $this->assertSame('refused', $check('/var/www/LeasyBack/logs'));
    }

    public function test_count_files_are_compared_exactly_with_volatile_tables_ignored(): void
    {
        $a = $this->tmp.DIRECTORY_SEPARATOR.'a.counts';
        $b = $this->tmp.DIRECTORY_SEPARATOR.'b.counts';
        file_put_contents($a, "b2b\t3\taaa\nusers\t5\tbbb\nsessions\t9\tccc\n");
        file_put_contents($b, "b2b\t3\taaa\nusers\t5\tbbb\nsessions\t12\tzzz\n");
        [$pa, $pb] = [$this->posix($a), $this->posix($b)];

        $run = fn (string $mode, string $ignore) => $this->sh("if cmp_counts $pa $pb $mode '$ignore' >/dev/null; then echo same; else echo different; fi")[0];

        $this->assertSame('same', $run('full', 'sessions'), 'only the volatile table differs');
        $this->assertSame('different', $run('full', '^$'));

        file_put_contents($b, "b2b\t3\tCHANGED\nusers\t5\tbbb\nsessions\t9\tccc\n");
        $this->assertSame('different', $run('full', '^$'), 'a changed row checksum is a difference');
        $this->assertSame('same', $run('counts', '^$'), 'but not when only counts are compared');

        file_put_contents($b, "b2b\t4\taaa\nusers\t5\tbbb\nsessions\t9\tccc\n");
        $this->assertSame('different', $run('counts', '^$'), 'a changed count is always a difference');

        file_put_contents($b, "users\t5\tbbb\nsessions\t9\tccc\n");
        $this->assertSame('different', $run('counts', '^$'), 'a missing table is a difference');
    }

    public function test_batch_ids_report_paths_and_actions_are_parsed_from_command_output(): void
    {
        $output = $this->tmp.DIRECTORY_SEPARATOR.'out.txt';
        file_put_contents($output, "| Entity | Action | Rows |\nImported. Batch 8f14e45f-ceea-467f-a0e6-3c5bb2d4d9d1. Report: /secure/legacy-rehearsal/x/reports/import-1/8f14e45f-ceea-467f-a0e6-3c5bb2d4d9d1\n");
        $o = $this->posix($output);

        $this->assertSame('8f14e45f-ceea-467f-a0e6-3c5bb2d4d9d1', $this->sh("parse_batch < $o")[0]);
        $this->assertSame('/secure/legacy-rehearsal/x/reports/import-1/8f14e45f-ceea-467f-a0e6-3c5bb2d4d9d1', $this->sh("parse_report < $o")[0]);

        $csv = $this->tmp.DIRECTORY_SEPARATOR.'reconciliation.csv';
        file_put_contents($csv, "entity,legacy_id,action,reason,detail\nkunde,abc,imported,,\nfahrzeug,def,imported,,\nfahrzeug,ghi,skipped,orphan_kunde,\ndokument,x#1,failed,download_failed,HTTP 404\n");
        $c = $this->posix($csv);

        $this->assertSame('2', $this->sh("count_action $c imported")[0]);
        $this->assertSame('1', $this->sh("count_action $c failed")[0]);
        $this->assertSame('0', $this->sh("count_action $c archived")[0]);
        $this->assertSame('0', $this->sh('count_action /does/not/exist imported')[0]);
    }

    public function test_arguments_are_validated_before_anything_runs(): void
    {
        $run = fn (string $args) => $this->sh("( parse_args $args ) >/dev/null 2>&1; echo rc=\$?")[0];

        $this->assertSame('rc=64', $run(''), 'no mode');
        $this->assertSame('rc=64', $run('install'), 'unknown mode');
        $this->assertSame('rc=64', $run('full'), 'full needs --confirm');
        $this->assertSame('rc=64', $run('dry-run --confirm'), '--confirm belongs to full');
        $this->assertSame('rc=64', $run('check --bogus'), 'unknown option');
        $this->assertSame('rc=0', $run('check'));
        $this->assertSame('rc=0', $run('dry-run --probe-documents --maintenance'));
        $this->assertSame('rc=0', $run('full --confirm --sanitize-copy-queues'));
    }
}
