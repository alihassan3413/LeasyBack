<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderCreatedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $auftragsnummer,
        public string $provider,
        public string $licensePlate,
        public ?string $vin,
        public ?string $make,
        public ?string $model,
        public string $stationName,
        public string $stationAddress,
        public string $termin,
        public ?string $remarks,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('service@leasyback.com', 'LeasyBack'),
            subject: "New order created: {$this->auftragsnummer}",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    private function buildHtml(): string
    {
        $orderLink = "https://api.leasyback.com/admin-panel/fahrzeuge?auftragsnummer={$this->auftragsnummer}";
        $vin = $this->vin ?? '-';
        $make = $this->make ?? '-';
        $model = $this->model ?? '-';
        $remarks = $this->remarks ?? '-';

        return <<<HTML
<h2>New Order Created</h2>
<p>A new order has been created in LeasyBack.</p>
<p><a href="{$orderLink}" style="background:#111;color:#fff;padding:10px 15px;text-decoration:none;border-radius:5px;">View Order Details</a></p>
<h3>Order Details</h3>
<p><b>Auftragsnummer:</b> {$this->auftragsnummer}</p>
<p><b>Provider:</b> {$this->provider}</p>
<h3>Vehicle Details</h3>
<p><b>License Plate:</b> {$this->licensePlate}</p>
<p><b>VIN:</b> {$vin}</p>
<p><b>Make:</b> {$make}</p>
<p><b>Model:</b> {$model}</p>
<h3>Inspection Details</h3>
<p><b>Date:</b> {$this->termin}</p>
<p><b>Station:</b> {$this->stationName}</p>
<p><b>Address:</b> {$this->stationAddress}</p>
<h3>Remarks</h3>
<p>{$remarks}</p>
<p>Open order directly:<br><a href="{$orderLink}">{$orderLink}</a></p>
HTML;
    }
}
