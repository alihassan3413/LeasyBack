<?php

namespace App\Mail\Workshop;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invitation that carries a workshop's one-time quotation link.
 *
 * Sibling of WorkshopCommissionedMail and built the same way: its own mailable
 * rather than an OrderEventMail, because a workshop has no account, no portal
 * and no business knowing whose car this is.
 *
 * This is the one message in the system that contains a live credential. The
 * plaintext token exists for exactly as long as the invite request, and this is
 * where it goes — into the URL, in the body, addressed to the one workshop the
 * invitation was created for. Nothing else in the email needs protecting; the
 * appraisal detail sits behind the link, where `show_appraisal_amounts` already
 * governs it.
 */
class WorkshopQuotationRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $workshopLabel,
        public readonly string $orderReference,
        public readonly string $vehicleLabel,
        public readonly ?string $licensePlate,
        public readonly ?string $vin,
        public readonly ?string $firstRegistration,
        public readonly ?int $mileage,
        public readonly int $positionCount,
        /** Only set when the invitation allows the workshop to see what is budgeted. */
        public readonly ?string $requestedTotalNet,
        public readonly string $expiresOn,
        public readonly string $quotationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Angebotsanfrage %s — %s', $this->orderReference, $this->licensePlate ?? $this->vehicleLabel),
        );
    }

    /**
     * Only the derived line is passed explicitly; the promoted public properties
     * above already reach the view through Mailable::buildViewData().
     *
     * `$this` deliberately does not go in here — Mailable implements Renderable
     * and View::gatherData() renders any Renderable in its data, so a mailable
     * handed to its own view renders itself while rendering itself.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.workshop.quotation-requested',
            with: ['positionSummary' => $this->positionSummary()],
        );
    }

    public function positionSummary(): string
    {
        return $this->positionCount === 1
            ? '1 Schadenposition'
            : sprintf('%d Schadenpositionen', $this->positionCount);
    }
}
