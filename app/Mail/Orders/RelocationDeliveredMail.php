<?php

namespace App\Mail\Orders;

class RelocationDeliveredMail extends RelocationEventMail
{
    public function subjectLine(): string
    {
        return $this->reference().' wurde zugestellt';
    }

    public function heading(): string
    {
        return 'Fahrzeug zugestellt';
    }

    /**
     * @return list<string>
     */
    public function paragraphs(): array
    {
        return [
            'die Überführung ist erfolgt: '.$this->vehicleReference().' wurde an der Zieladresse übergeben.',
            'Die Abrechnung erhalten Sie in Kürze. Alle Unterlagen finden Sie im Portal.',
        ];
    }
}