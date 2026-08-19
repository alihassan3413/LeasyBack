<?php

namespace App\Mail\Workshop;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The repair order sent to the workshop whose quotation the customer accepted.
 * The first outbound communication LeasyBack has ever had with a workshop.
 *
 * Deliberately **not** an OrderEventMail. That base class exists for the
 * customer: it greets a recipient by name, renders `OrderEmailData`'s details
 * block and links to the portal. A workshop is a supplier with no account, no
 * portal and no business knowing whose car this is — reusing that base would
 * have made leaking customer data the default and withholding it the exception.
 *
 * What goes in is only what is needed to do the job: the order reference to
 * quote back, the car, the positions the customer approved with the workshop's
 * own prices, and what to do next. What stays out is the customer's name,
 * address and email, every competing quotation, every rejected offer, the
 * appraisal amounts the repair was measured against, internal notes and the
 * order's lifecycle state.
 */
class WorkshopCommissionedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{component: string, repair_method: string|null, amount_net: string|null, not_repairable: bool}>  $positions
     */
    public function __construct(
        public readonly string $workshopName,
        public readonly string $orderReference,
        public readonly string $vehicleLabel,
        public readonly ?string $licensePlate,
        public readonly ?string $vin,
        public readonly array $positions,
        public readonly ?string $totalNet,
        public readonly ?string $earliestRepairStart,
        public readonly ?int $processingDays,
        public readonly ?string $confirmedRepairStart,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Reparaturauftrag %s — %s', $this->orderReference, $this->licensePlate ?? $this->vehicleLabel),
        );
    }

    /**
     * The public promoted properties above are already handed to the view by
     * Mailable::buildViewData(), so only the derived line is passed explicitly.
     *
     * Passing `$this` in here instead would look tidier and recurse forever:
     * Mailable implements Renderable, and View::gatherData() calls render() on
     * any Renderable in its data — so the mailable would render itself while
     * rendering itself.
     */
    public function content(): Content
    {
        return new Content(view: 'emails.workshop.commissioned', with: ['instruction' => $this->instruction()]);
    }

    /**
     * The one thing the workshop is being asked to do. Once a repair start is
     * already agreed there is nothing to confirm, so the instruction changes
     * rather than asking for a date that exists.
     */
    public function instruction(): string
    {
        if ($this->confirmedRepairStart !== null) {
            return sprintf(
                'Der Reparaturbeginn ist auf den %s abgestimmt. Bitte bestätigen Sie uns kurz den Eingang dieses Auftrags.',
                $this->confirmedRepairStart,
            );
        }

        return 'Bitte bestätigen Sie uns kurzfristig einen konkreten Reparaturtermin per Antwort auf diese E-Mail.';
    }
}
