<?php

namespace App\Mail\Orders;

class RelocationRequestedMail extends RelocationEventMail
{
    public function subjectLine(): string
    {
        return 'Ihre Überführung für '.$this->reference().' ist eingegangen';
    }

    public function heading(): string
    {
        return 'Überführung angefragt';
    }

    /**
     * @return list<string>
     */
    public function paragraphs(): array
    {
        return [
            'vielen Dank für Ihre Anfrage. Wir haben die Überführung von '.$this->vehicleReference().' erhalten.',
            'Wir prüfen Ihren Wunschtermin und melden uns mit einer Terminbestätigung. Den aktuellen Stand sehen Sie jederzeit im Portal.',
        ];
    }
}