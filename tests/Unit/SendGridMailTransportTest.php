<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/**
 * Checkpoint 5: verifies the SendGrid mailer config actually resolves to a
 * working SMTP transport — not just that the config array has the right
 * shape. SendGrid's SMTP relay is used deliberately over the Symfony
 * sendgrid-mailer bridge package: it needs zero new dependencies and is
 * SendGrid's own documented Laravel integration method.
 */
class SendGridMailTransportTest extends TestCase
{
    public function test_sendgrid_mailer_resolves_to_an_smtp_transport(): void
    {
        config(['mail.mailers.sendgrid.password' => 'dummy-test-key-for-transport-resolution-only']);

        $transport = Mail::mailer('sendgrid')->getSymfonyTransport();

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
    }

    public function test_sendgrid_mailer_targets_the_sendgrid_smtp_relay(): void
    {
        config(['mail.mailers.sendgrid.password' => 'dummy-test-key-for-transport-resolution-only']);

        $transport = Mail::mailer('sendgrid')->getSymfonyTransport();

        $this->assertStringContainsString('smtp.sendgrid.net', (string) $transport);
    }

    public function test_sendgrid_mailer_uses_the_literal_apikey_username(): void
    {
        // SendGrid's SMTP relay requires the username to be the literal string
        // "apikey" — the API key itself goes in the password field. Easy to
        // get backwards; locking it in as a regression guard.
        $this->assertSame('apikey', config('mail.mailers.sendgrid.username'));
    }

    public function test_sendgrid_api_key_comes_from_the_environment_not_a_hardcoded_default(): void
    {
        // config/mail.php must never fall back to a literal API key string —
        // regression guard against accidentally hardcoding a credential.
        $this->assertSame(env('SENDGRID_API_KEY'), config('mail.mailers.sendgrid.password'));
    }
}
