<?php

namespace App\Mail\Orders;

class B2bCollectionRequestedMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Ihr Rückgabeauftrag ist eingegangen – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Auftrag eingegangen';
    }

    public function heading(): string
    {
        return 'Ihr Rückgabeauftrag ist eingegangen';
    }

    public function paragraphs(): array
    {
        return [
            'vielen Dank für Ihren Rückgabeauftrag zu '.$this->vehicleReference().'.',
            'Wir prüfen Ihren Wunschtermin für die Abholung und bestätigen Ihnen den Abholtermin in Kürze per E-Mail. Sie müssen das Fahrzeug nirgendwohin bringen – wir holen es bei Ihnen ab.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Auftrag ansehen';
    }
}
