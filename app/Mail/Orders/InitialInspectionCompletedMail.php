<?php

namespace App\Mail\Orders;

class InitialInspectionCompletedMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Erstbegutachtung abgeschlossen – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Begutachtung';
    }

    public function heading(): string
    {
        return 'Die Erstbegutachtung ist abgeschlossen';
    }

    public function paragraphs(): array
    {
        return [
            'die Erstbegutachtung von '.$this->vehicleReference().' wurde abgeschlossen.',
            'Das Ergebnis der Begutachtung steht Ihnen im Portal zur Verfügung. Sollte eine Reparatur erforderlich sein, erhalten Sie in Kürze ein Angebot zur Freigabe.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Ergebnis ansehen';
    }
}
