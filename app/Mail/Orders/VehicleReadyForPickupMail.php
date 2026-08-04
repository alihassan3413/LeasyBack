<?php

namespace App\Mail\Orders;

class VehicleReadyForPickupMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Ihr Fahrzeug ist abholbereit – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Abholbereit';
    }

    public function heading(): string
    {
        return 'Ihr Fahrzeug ist abholbereit';
    }

    public function paragraphs(): array
    {
        return [
            'alle Arbeiten an '.$this->vehicleReference().' sind abgeschlossen – Ihr Fahrzeug steht zur Abholung bereit.',
            'Bitte bringen Sie zur Abholung Ihre Fahrzeugpapiere und einen gültigen Ausweis mit. Alle Details zum Auftrag finden Sie im Portal.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Abholung ansehen';
    }
}
