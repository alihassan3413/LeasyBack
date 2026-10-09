<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The one go-live email to a user imported from Base44: their account moved
 * to the new portal, and a one-time link to set a password. It carries no
 * password. Sent from inside SendLegacyActivationMail (which is the queued
 * part), so the job knows whether it went out.
 */
class LegacyAccountActivation extends Mailable
{
    public function __construct(
        public readonly User $user,
        public readonly string $activationUrl,
        public readonly int $expiresInDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ihr Konto im neuen LeasyBack-Portal — bitte aktivieren');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.legacy-activation',
            with: [
                'name' => trim((string) $this->user->name) ?: null,
                'activationUrl' => $this->activationUrl,
                'expiresInDays' => $this->expiresInDays,
                'forgotUrl' => route('password.request'),
            ],
        );
    }
}
