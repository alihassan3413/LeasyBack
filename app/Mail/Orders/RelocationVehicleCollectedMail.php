<?php

namespace App\Mail\Orders;

class RelocationVehicleCollectedMail extends RelocationEventMail
{
    public function subjectLine(): string
    {
        return $this->reference().' wurde abgeholt';
    }

    public function heading(): string
    {
        return 'Fahrzeug abgeholt';
    }

    /**
     * @return list<string>
     */
    public function paragraphs(): array
    {
        return [
            'die Überführung hat begonnen: '.$this->vehicleReference().' wurde abgeholt und ist jetzt auf dem Weg zur Zieladresse.',
            'Wir informieren Sie, sobald das Fahrzeug übergeben wurde.',
        ];
    }
}