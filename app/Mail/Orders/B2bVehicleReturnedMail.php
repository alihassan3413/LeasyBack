<?php

namespace App\Mail\Orders;

class B2bVehicleReturnedMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Fahrzeug an Leasinggeber übergeben – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Rückgabe erfolgt';
    }

    public function heading(): string
    {
        return 'Ihr Fahrzeug wurde an den Leasinggeber übergeben';
    }

    public function paragraphs(): array
    {
        return [
            'die Rückgabe zu '.$this->vehicleReference().' an den Leasinggeber ist erfolgt.',
            'Wir erstellen nun die Abrechnung. Die Rückgabeunterlagen stehen Ihnen im Portal zur Verfügung.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Unterlagen ansehen';
    }
}
