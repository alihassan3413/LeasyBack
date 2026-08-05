<?php

namespace App\Mail\Orders;

class OfferApprovalReminderMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Erinnerung: Ihr Reparaturangebot wartet auf Freigabe – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Aktion erforderlich';
    }

    public function heading(): string
    {
        return 'Ihr Reparaturangebot wartet weiterhin auf Ihre Freigabe';
    }

    public function paragraphs(): array
    {
        return [
            'für '.$this->vehicleReference().' liegt weiterhin ein Reparaturangebot zur Freigabe bereit.',
            'Solange keine Freigabe erfolgt, können wir die Werkstatt nicht beauftragen und das Fahrzeug bleibt in der Angebotsphase. Sie können das Angebot im Portal freigeben oder ablehnen.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Angebot jetzt entscheiden';
    }
}
