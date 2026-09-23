<?php

namespace App\Mail\Orders;

class B2bCollectionRescheduledMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Abholtermin geändert – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Termin geändert';
    }

    public function heading(): string
    {
        return 'Ihr Abholtermin wurde geändert';
    }

    public function paragraphs(): array
    {
        return [
            'der Abholtermin zu '.$this->vehicleReference().' wurde verschoben.',
            'Den neuen Termin finden Sie unten. Bitte stellen Sie das Fahrzeug zu diesem Termin an der Abholadresse bereit.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Termindetails ansehen';
    }
}
