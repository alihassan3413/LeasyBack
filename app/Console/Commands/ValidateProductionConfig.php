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

        /*
         * Stripe. Checked unconditionally rather than behind isProduction():
         * the whole point of this command is to judge a config *as* a
         * production config, exactly as the app.debug and session.secure
         * checks above already do.
         */
        $missingStripeKeys = array_keys(array_filter([
            'STRIPE_KEY' => empty(config('services.stripe.key')),
            'STRIPE_SECRET' => empty(config('services.stripe.secret')),
            'STRIPE_WEBHOOK_SECRET' => empty(config('services.stripe.webhook_secret')),
        ]));

        if ($missingStripeKeys !== []) {
            $critical[] = sprintf(
                '%s not set — B2C payments cannot run (a missing webhook secret also makes the Stripe endpoint answer 503 by design).',
                implode(', ', $missingStripeKeys),
            );
        }

        /*
         * A test-mode secret in production is worse than no secret at all:
         * charges appear to succeed, customers are told their repair is paid,
         * and no money ever moves.
         */
        $testModeCredentials = array_keys(array_filter([
            'STRIPE_SECRET' => str_starts_with((string) config('services.stripe.secret'), 'sk_test_'),
            'STRIPE_KEY' => str_starts_with((string) config('services.stripe.key'), 'pk_test_'),
        ]));

        if ($testModeCredentials !== []) {
            $critical[] = sprintf(
                '%s is a Stripe test-mode credential — payments would appear to succeed while collecting nothing.',
                implode(' and ', $testModeCredentials),
            );
        }

        if ((int) config('payments.cancellation_fee_cents') <= 0) {
            $warnings[] = 'payments.cancellation_fee_cents is not positive — customers acknowledge a cancellation fee at booking that would then never be charged.';
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
