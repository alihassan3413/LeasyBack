<?php

namespace App\Mail\Orders;

class RelocationCompletedMail extends RelocationEventMail
{
    public function subjectLine(): string
    {
        return 'Überführung von '.$this->reference().' abgeschlossen';
    }

    public function heading(): string
    {
        return 'Überführung abgeschlossen';
    }

    /**
     * @return list<string>
     */
    public function paragraphs(): array
    {
        return [
            'die Überführung von '.$this->vehicleReference().' ist abgeschlossen. Es steht nichts mehr aus.',
            'Alle Unterlagen zu diesem Auftrag bleiben im Portal für Sie verfügbar.',
        ];
    }
}