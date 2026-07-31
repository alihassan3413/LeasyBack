<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Deployment smoke test: catches the specific misconfigurations that would
 * otherwise silently ship to production (most importantly APP_DEBUG=true,
 * which the .env.example default leaves on, and would leak stack traces —
 * see the Checkpoint 4 exception handler). Intended to run in a deploy
 * pipeline, failing the deploy on critical issues.
 */
class ValidateProductionConfig extends Command
{
    protected $signature = 'config:validate-production';

    protected $description = 'Validate production-critical configuration (fails on critical issues, warns on advisory ones).';

    public function handle(): int
    {
        $critical = [];
        $warnings = [];

        if (config('app.debug') === true) {
            $critical[] = 'APP_DEBUG is true — stack traces and internal details can leak to clients.';
        }

        if (empty(config('app.key'))) {
            $critical[] = 'APP_KEY is not set — sessions, encrypted cookies, and signed URLs are not secure.';
        }

        $corsOrigins = config('cors.allowed_origins', []);
        if (in_array('*', $corsOrigins, true)) {
            $critical[] = 'CORS allowed_origins contains "*" — any origin can make credentialed cross-origin requests.';
        }
        if (empty($corsOrigins)) {
            $critical[] = 'CORS allowed_origins is empty — no frontend origin is explicitly trusted.';
        }

        if (config('hashing.driver') !== 'argon2id') {
            $warnings[] = "hashing.driver is '".config('hashing.driver')."', expected 'argon2id' (see docs/AUTH_PRODUCTION_IMPLEMENTATION_PLAN.md Checkpoint 1).";
        }

        if (config('mail.default') === 'log') {
            $warnings[] = 'mail.default is "log" — no email is actually being delivered (registration/reset emails will not reach users).';
        }

        if (config('queue.default') === 'sync') {
            $warnings[] = 'queue.default is "sync" — queued jobs run inline with no retry/failure visibility.';
        }

        if (config('session.driver') === 'array') {
            $warnings[] = 'session.driver is "array" — sessions do not persist across requests.';
        }

        if (! config('session.secure')) {
            $warnings[] = 'session.secure is not enabled — session cookies can be sent over plain HTTP.';
        }

        foreach ($critical as $message) {
            $this->components->error($message);
        }

        foreach ($warnings as $message) {
            $this->components->warn($message);
        }

        if (empty($critical) && empty($warnings)) {
            $this->components->info('Production configuration looks good.');
        }

        return empty($critical) ? self::SUCCESS : self::FAILURE;
    }
}
