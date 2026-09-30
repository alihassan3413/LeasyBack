<?php

namespace App\Mail\Orders;

class RelocationScheduledMail extends RelocationEventMail
{
    public function subjectLine(): string
    {
        return 'Abholtermin für die Überführung von '.$this->reference().' bestätigt';
    }

    public function heading(): string
    {
        return 'Überführung terminiert';
    }

    /**
     * @return list<string>
     */
    public function paragraphs(): array
    {
        return [
            'der Abholtermin für die Überführung von '.$this->vehicleReference().' wurde bestätigt. Die Details finden Sie im Portal.',
            'Bitte stellen Sie sicher, dass Fahrzeug, Schlüssel und Fahrzeugpapiere zum vereinbarten Termin an der Abholadresse bereitliegen.',
        ];
    }
}