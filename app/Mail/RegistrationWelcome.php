<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationWelcome extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The number of times the queued job may be attempted.
     */
    public int $tries = 3;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public readonly User $user,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Willkommen im Leasyback-Portal',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.registration-welcome',
            with: [
                'userName' => $this->resolveUserName(),
                'loginUrl' => rtrim((string) config('app.frontend_url'), '/').'/login',
            ],
        );
    }

    private function resolveUserName(): ?string
    {
        $name = trim((string) $this->user->name);
        $email = trim((string) $this->user->email);
        $emailLocalPart = explode('@', $email)[0];

        if ($name === '' || strcasecmp($name, $email) === 0 || strcasecmp($name, $emailLocalPart) === 0) {
            return null;
        }

        return $name;
    }
}
