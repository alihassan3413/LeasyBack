<?php

namespace App\Mail\Orders;

class AccidentDamageCompletedMail extends AccidentDamageEventMail
{
    public function subjectLine(): string
    {
        return 'Unfallschaden '.$this->reference().' abgeschlossen';
    }

    public function heading(): string
    {
        return 'Unfallschaden abgeschlossen';
    }

    /**
     * @return list<string>
     */
    public function paragraphs(): array
    {
        return [
            'die Bearbeitung des Unfallschadens an '.$this->vehicleReference().' ist abgeschlossen.',
            'Die Abschlussdokumentation steht Ihnen im Portal zur Verfügung.',
        ];
    }
}