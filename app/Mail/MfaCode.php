<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use SensitiveParameter;

/**
 * A one-time sign-in code.
 *
 * Deliberately not queued. A code the user is waiting for must not sit behind
 * whatever else is on the queue, and it expires in ten minutes — a delayed
 * delivery is a failed login, not a late email.
 *
 * The code is a live credential for those ten minutes, which is why nothing
 * else about the account goes in the body.
 */
class MfaCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        #[SensitiveParameter] public readonly string $code,
        public readonly int $minutes,
        public readonly string $name,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ihr Anmeldecode für LeasyBack');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.mfa-code');
    }
}
