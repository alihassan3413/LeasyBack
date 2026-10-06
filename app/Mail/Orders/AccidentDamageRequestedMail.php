<?php

namespace App\Mail\Orders;

class AccidentDamageRequestedMail extends AccidentDamageEventMail
{
    public function subjectLine(): string
    {
        return 'Ihre Unfallschadenmeldung für '.$this->reference().' ist eingegangen';
    }

    public function heading(): string
    {
        return 'Unfallschaden gemeldet';
    }

    /**
     * @return list<string>
     */
    public function paragraphs(): array
    {
        return [
            'vielen Dank für Ihre Meldung. Wir haben den Unfallschaden an '.$this->vehicleReference().' erhalten.',
            'Wir stimmen den nächsten Schritt ab — Begutachtung, Fahrzeugzugang oder Abholung — und melden uns bei Ihnen. Den aktuellen Stand sehen Sie jederzeit im Portal.',
        ];
    }
}