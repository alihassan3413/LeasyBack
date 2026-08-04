<?php

namespace App\Mail\Orders;

use App\Services\Mail\OrderEmailData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

abstract class OrderEventMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly OrderEmailData $data) {}

    abstract public function subjectLine(): string;

    abstract public function heading(): string;

    /**
     * @return list<string>
     */
    abstract public function paragraphs(): array;

    public function eyebrow(): string
    {
        return 'Auftragsupdate';
    }

    public function ctaLabel(): string
    {
        return 'Zum Portal';
    }

    public function preheader(): string
    {
        return $this->heading();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.event',
            with: [
                'data' => $this->data,
                'preheader' => $this->preheader(),
                'eyebrow' => $this->eyebrow(),
                'heading' => $this->heading(),
                'paragraphs' => $this->paragraphs(),
                'ctaLabel' => $this->ctaLabel(),
            ],
        );
    }

    protected function reference(): string
    {
        return $this->data->licensePlate
            ?? $this->data->orderNumber
            ?? 'Ihrem Fahrzeug';
    }

    protected function vehicleReference(): string
    {
        return $this->data->licensePlate !== null
            ? 'Ihrem Fahrzeug mit dem Kennzeichen '.$this->data->licensePlate
            : 'Ihrem Fahrzeug';
    }
}
