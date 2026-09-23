<?php

namespace App\Mail\Orders;

class B2bVehicleCollectedMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Fahrzeug abgeholt – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Fahrzeug abgeholt';
    }

    public function heading(): string
    {
        return 'Ihr Fahrzeug wurde abgeholt';
    }

    public function paragraphs(): array
    {
        return [
            'die Abholung zu '.$this->vehicleReference().' ist erfolgt.',
            'Als Nächstes wird das Fahrzeug begutachtet. Sobald das Gutachten vorliegt, finden Sie es im Portal.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Status ansehen';
    }
}
