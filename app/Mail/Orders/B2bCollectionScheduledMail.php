<?php

namespace App\Mail\Orders;

class B2bCollectionScheduledMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Abholtermin bestätigt – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Abholung geplant';
    }

    public function heading(): string
    {
        return 'Ihr Abholtermin ist bestätigt';
    }

    public function paragraphs(): array
    {
        return [
            'die Abholung von '.$this->vehicleReference().' ist eingeplant.',
            'Bitte stellen Sie sicher, dass das Fahrzeug zum unten genannten Termin an der Abholadresse bereitsteht und die Fahrzeugschlüssel sowie die Fahrzeugpapiere übergeben werden können.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Termindetails ansehen';
    }
}
