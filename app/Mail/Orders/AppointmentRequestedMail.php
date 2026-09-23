<?php

namespace App\Mail\Orders;

class AppointmentRequestedMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Ihre Terminanfrage ist eingegangen – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Terminanfrage';
    }

    public function heading(): string
    {
        return 'Ihre Terminanfrage ist eingegangen';
    }

    public function paragraphs(): array
    {
        return [
            'vielen Dank für Ihre Terminanfrage zu '.$this->vehicleReference().'.',
            'Wir prüfen Ihre Anfrage und stimmen den Termin mit der Prüfstation ab. Sobald der Termin feststeht, erhalten Sie von uns eine Bestätigung per E-Mail.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Anfrage ansehen';
    }
}
