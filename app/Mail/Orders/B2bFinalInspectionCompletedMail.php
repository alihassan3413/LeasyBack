<?php

namespace App\Mail\Orders;

class B2bFinalInspectionCompletedMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Nachbegutachtung abgeschlossen – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Nachbegutachtung';
    }

    public function heading(): string
    {
        return 'Die Nachbegutachtung ist abgeschlossen';
    }

    public function paragraphs(): array
    {
        return [
            'die Nachbegutachtung von '.$this->vehicleReference().' nach der Reparatur ist abgeschlossen.',
            'Als Nächstes übergeben wir das Fahrzeug an den Leasinggeber. Wir informieren Sie, sobald die Rückgabe erfolgt ist.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Status ansehen';
    }
}
