<?php

namespace App\Mail\Orders;

class FinalInspectionCompletedMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Nachprüfung abgeschlossen – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Nachprüfung';
    }

    public function heading(): string
    {
        return 'Die Nachprüfung ist abgeschlossen';
    }

    public function paragraphs(): array
    {
        return [
            'die abschließende Nachprüfung von '.$this->vehicleReference().' wurde durchgeführt.',
            'Das Ergebnis finden Sie im Portal. Wir melden uns, sobald Ihr Fahrzeug zur Abholung bereitsteht.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Ergebnis ansehen';
    }
}
