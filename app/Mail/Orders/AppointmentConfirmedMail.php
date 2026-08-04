<?php

namespace App\Mail\Orders;

class AppointmentConfirmedMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Ihr Termin ist bestätigt – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Termin bestätigt';
    }

    public function heading(): string
    {
        return 'Ihr Termin ist bestätigt';
    }

    public function paragraphs(): array
    {
        return [
            'der Termin für die Begutachtung von '.$this->vehicleReference().' wurde bestätigt.',
            'Bitte stellen Sie das Fahrzeug pünktlich an der unten genannten Prüfstation bereit und halten Sie die Fahrzeugpapiere bereit.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Termindetails ansehen';
    }
}
