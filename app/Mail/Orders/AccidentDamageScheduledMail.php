<?php

namespace App\Mail\Orders;

class AccidentDamageScheduledMail extends AccidentDamageEventMail
{
    public function subjectLine(): string
    {
        return 'Unfallschaden '.$this->reference().': nächster Schritt terminiert';
    }

    public function heading(): string
    {
        return 'Nächster Schritt terminiert';
    }

    /**
     * @return list<string>
     */
    public function paragraphs(): array
    {
        return [
            'für den Unfallschaden an '.$this->vehicleReference().' ist der nächste Schritt vereinbart und bestätigt.',
            'Termin und Art des Schritts finden Sie im Portal.',
        ];
    }
}