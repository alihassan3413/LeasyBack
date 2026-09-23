<?php

namespace App\Mail\Orders;

class VehicleInRepairMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Ihr Fahrzeug ist in der Werkstatt – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'In Werkstatt';
    }

    public function heading(): string
    {
        return 'Ihr Fahrzeug ist in der Werkstatt';
    }

    public function paragraphs(): array
    {
        return [
            'die Reparatur von '.$this->vehicleReference().' hat begonnen.',
            'Sobald die Arbeiten abgeschlossen sind, wird das Fahrzeug nachgeprüft. Wir halten Sie über jeden weiteren Schritt auf dem Laufenden.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Status ansehen';
    }
}
