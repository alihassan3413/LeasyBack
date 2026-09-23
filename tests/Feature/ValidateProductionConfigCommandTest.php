<?php

namespace Tests\Feature;

use Tests\TestCase;

class ValidateProductionConfigCommandTest extends TestCase
{
    /**
     * A config set with no critical issues and no advisory warnings —
     * every other test starts here and deliberately breaks one thing,
     * rather than relying on phpunit.xml's ambient test environment
     * (which itself uses several intentionally test-friendly, non-production
     * values like SESSION_DRIVER=array).
     */
    private function applyCleanProductionLikeConfig(): void
    {
        config([
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'cors.allowed_origins' => ['https://app.leasyback.com'],
            'hashing.driver' => 'argon2id',
            'mail.default' => 'sendgrid',
            'queue.default' => 'database',
            'session.driver' => 'database',
            'session.secure' => true,
            // Live-mode-shaped Stripe credentials. The repo's own .env holds
            // sandbox keys, which this command is supposed to reject, so the
            // clean baseline cannot inherit them.
            'services.stripe.key' => 'pk_live_clean',
            'services.stripe.secret' => 'sk_live_clean',
            'services.stripe.webhook_secret' => 'whsec_clean',
            'payments.cancellation_fee_cents' => 20000,
        ]);
    }

    public function test_clean_config_passes_with_no_issues(): void
    {
        $this->applyCleanProductionLikeConfig();

        $this->artisan('config:validate-production')
            ->assertExitCode(0)
            ->expectsOutputToContain('Production configuration looks good.');
    }

    public function test_app_debug_true_is_critical(): void
    {
        $this->applyCleanProductionLikeConfig();
        config(['app.debug' => true]);

        $this->artisan('config:validate-production')
            ->assertExitCode(1)
            ->expectsOutputToContain('APP_DEBUG is true');
    }

    public function test_missing_app_key_is_critical(): void
    {
        $this->applyCleanProductionLikeConfig();
        config(['app.key' => '']);

        $this->artisan('config:validate-production')
            ->assertExitCode(1)
            ->expectsOutputToContain('APP_KEY is not set');
    }

    public function test_wildcard_cors_origin_is_critical(): void
    {
        $this->applyCleanProductionLikeConfig();
        config(['cors.allowed_origins' => ['*']]);

        $this->artisan('config:validate-production')
            ->assertExitCode(1)
            ->expectsOutputToContain('CORS allowed_origins contains "*"');
    }

    public function test_empty_cors_origins_is_critical(): void
    {
        $this->applyCleanProductionLikeConfig();
        config(['cors.allowed_origins' => []]);

        $this->artisan('config:validate-production')
            ->assertExitCode(1)
            ->expectsOutputToContain('CORS allowed_origins is empty');
    }

    public function test_non_argon2id_hashing_driver_is_a_warning_not_critical(): void
    {
        $this->applyCleanProductionLikeConfig();
        config(['hashing.driver' => 'bcrypt']);

        $this->artisan('config:validate-production')
            ->assertExitCode(0)
            ->expectsOutputToContain("hashing.driver is 'bcrypt'");
    }

    public function test_log_mailer_is_a_warning_not_critical(): void
    {
        $this->applyCleanProductionLikeConfig();
        config(['mail.default' => 'log']);

        $this->artisan('config:validate-production')
            ->assertExitCode(0)
            ->expectsOutputToContain('no email is actually being delivered');
    }

    public function test_sync_queue_is_a_warning_not_critical(): void
    {
        $this->applyCleanProductionLikeConfig();
        config(['queue.default' => 'sync']);

        $this->artisan('config:validate-production')
            ->assertExitCode(0)
            ->expectsOutputToContain('no retry/failure visibility');
    }

    public function test_array_session_driver_is_a_warning_not_critical(): void
    {
        $this->applyCleanProductionLikeConfig();
        config(['session.driver' => 'array']);

        $this->artisan('config:validate-production')
            ->assertExitCode(0)
            ->expectsOutputToContain('sessions do not persist');
    }

    public function test_insecure_session_cookie_is_a_warning_not_critical(): void
    {
        $this->applyCleanProductionLikeConfig();
        config(['session.secure' => false]);

        $this->artisan('config:validate-production')
            ->assertExitCode(0)
            ->expectsOutputToContain('session cookies can be sent over plain HTTP');
    }

    public function test_missing_stripe_webhook_secret_is_critical(): void
    {
        $this->applyCleanProductionLikeConfig();
        config(['services.stripe.webhook_secret' => '']);

        $this->artisan('config:validate-production')
            ->assertExitCode(1)
            ->expectsOutputToContain('STRIPE_WEBHOOK_SECRET not set');
    }

    /**
     * All three are named in one message so a fresh deployment is told
     * everything it is missing at once, rather than one key per run.
     */
    public function test_all_missing_stripe_keys_are_reported_together(): void
    {
        $this->applyCleanProductionLikeConfig();
        config([
            'services.stripe.key' => '',
            'services.stripe.secret' => '',
            'services.stripe.webhook_secret' => '',
        ]);

        $this->artisan('config:validate-production')
            ->assertExitCode(1)
            ->expectsOutputToContain('STRIPE_KEY, STRIPE_SECRET, STRIPE_WEBHOOK_SECRET not set');
    }

    /**
     * The failure mode this guards is silent: a test-mode secret takes no
     * money while every charge still reports success, so the customer is told
     * their repair is paid and the vehicle is released for nothing.
     */
    public function test_test_mode_stripe_secret_is_critical(): void
    {
        $this->applyCleanProductionLikeConfig();
        config(['services.stripe.secret' => 'sk_test_51RTfFo']);

        $this->artisan('config:validate-production')
            ->assertExitCode(1)
            ->expectsOutputToContain('STRIPE_SECRET is a Stripe test-mode credential');
    }

    public function test_test_mode_publishable_key_is_critical(): void
    {
        $this->applyCleanProductionLikeConfig();
        config(['services.stripe.key' => 'pk_test_51RTfFo']);

        $this->artisan('config:validate-production')
            ->assertExitCode(1)
            ->expectsOutputToContain('STRIPE_KEY is a Stripe test-mode credential');
    }

    public function test_zero_cancellation_fee_is_a_warning_not_critical(): void
    {
        $this->applyCleanProductionLikeConfig();
        config(['payments.cancellation_fee_cents' => 0]);

        $this->artisan('config:validate-production')
            ->assertExitCode(0)
            ->expectsOutputToContain('would then never be charged');
    }
}
