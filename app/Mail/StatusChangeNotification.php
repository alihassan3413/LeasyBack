<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StatusChangeNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $firstName,
        public string $licensePlate,
        public ?string $actionUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('service@leasyback.com', 'LeasyBack'),
            subject: "Neues Update zu Ihrem Leasingfahrzeug mit Kennzeichen: {$this->licensePlate}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.status-notify',
            with: [
                'firstName' => $this->firstName ?: 'Kunde',
                'licensePlate' => $this->licensePlate,
                'actionUrl' => $this->actionUrl,
            ],
        );
    }
}
